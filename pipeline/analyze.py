# -*- coding: utf-8 -*-
# ============================================================
# analyze.py — 世界A（解析パイプライン）本体
#   青空文庫テキスト → 決定論的にノード/エッジ/エビデンスを抽出 → JSON出力
#   出典・仕様: specs/world-a-pipeline.md / idea.md §1-2-6 / CLAUDE.md §5(ADR)
#
#   設計原則（最重要）:
#     - ADR-002 決定論: LLM・乱数は一切使わない。同入力→同出力（再現性）。
#     - ADR-003 エビデンス: 全エッジに「原文の何文目・どの位置か」を紐づける。
#     - 方針A: 一次データ（事実）のみ出力。edge_type は中立ラベル「共起」。
#              「恋慕」等の意味ラベルは二次データ（AI解釈・破線）で別途（本スクリプトの対象外）。
#     - ANT視点: 人間を特権化せず person/place/term を対等にノード化（3層）。
#
#   実行:
#     python pipeline/analyze.py
#   依存:
#     GiNZA（ja_ginza）。未導入なら term を抽出せず person/place のみで動く（骨格検証用フォールバック）。
# ============================================================

import json
import os
import re
from collections import defaultdict
from itertools import combinations

# ------------------------------------------------------------
# 設定（この先頭定数だけ触れば挙動を調整できる）
# ------------------------------------------------------------
BASE_DIR   = os.path.dirname(os.path.abspath(__file__))       # pipeline/
SOURCE_TXT = os.path.join(BASE_DIR, "source", "kokoro.txt")   # 入力（UTF-8）
OUT_DIR    = os.path.join(BASE_DIR, "output")
NODES_JSON = os.path.join(OUT_DIR, "nodes.json")
EDGES_JSON = os.path.join(OUT_DIR, "edges.json")

# 作品メタ（nodes.json の work。import.php が works テーブルへ入れる）
WORK_META = {
    "title":  "こころ",
    "author": "夏目漱石",
    "source": "青空文庫 https://www.aozora.gr.jp/cards/000148/card773.html",
}

# 閾値（specs §3.3 / §4.3 / §5）
FREQ_MIN     = 20  # term ノードの最小総出現回数（person/place は辞書/NERで残す）
COOC_MIN     = 3   # エッジ化に必要な最小共起文数
EVIDENCE_MAX = 5   # 1エッジあたりのエビデンス最大件数

# 人物辞書（開発時に人手で用意＝決定論・原則OK）。surface -> 正規化ラベル。
#   表記ゆれ（御嬢さん→お嬢さん 等）をここで吸収する（specs §3.1）。
#   単漢字（母/父 等）は「叔母/祖母」等への誤マッチを避けるため v1では入れない。
PERSON_DICT = {
    "私":       "私",
    "先生":     "先生",
    "Ｋ":       "K",
    "K":        "K",
    "奥さん":   "奥さん",
    "御嬢さん": "お嬢さん",
    "お嬢さん": "お嬢さん",
}

# 場所辞書（NERでも取れるが確実性のため辞書併用）。surface -> ラベル。
PLACE_DICT = {
    "鎌倉": "鎌倉",
    "東京": "東京",
}

# term から除外する語（形式名詞・機能語・汎用語）。GiNZAの lemma と照合。
TERM_STOPWORDS = {
    "事", "物", "方", "為", "ため", "よう", "の", "もの", "こと", "とき", "時",
    "中", "上", "下", "内", "際", "点", "ところ", "所", "人", "彼", "彼女",
    "自分", "何", "誰", "これ", "それ", "あれ", "どれ", "ここ", "そこ", "あそこ",
    "とき", "うち", "ほう", "はず", "わけ", "つもり", "そう", "みたい",
}


# ------------------------------------------------------------
# 1. 読み込みと前処理（青空文庫記法の除去）— specs §2
# ------------------------------------------------------------
def load_and_clean(path):
    """テキストを読み、ヘッダ/底本/見出し/ルビ/注記を除去して本文だけにする。"""
    with open(path, "r", encoding="utf-8") as f:
        text = f.read()
    text = text.replace("\r\n", "\n").replace("\r", "\n")
    lines = text.split("\n")

    # --- ヘッダ（記号説明ブロック）の除去: 2本目の区切り線 "----..." までを捨てる ---
    dash_idx = [i for i, ln in enumerate(lines) if re.match(r"^-{5,}$", ln.strip())]
    if len(dash_idx) >= 2:
        lines = lines[dash_idx[1] + 1:]

    # --- 底本ブロックの除去: "底本：" で始まる行以降を捨てる ---
    for i, ln in enumerate(lines):
        if ln.strip().startswith("底本："):
            lines = lines[:i]
            break

    # --- 見出し行（大/中/小見出し注記を含む行）を丸ごと除去 ---
    heading_re = re.compile(r"［＃「[^」]*」は(?:大|中|小)見出し］")
    lines = [ln for ln in lines if not heading_re.search(ln)]

    body = "\n".join(lines)

    # --- 記法の除去（順序重要: 外字注記 → 一般注記 → ルビ → ルビ開始記号） ---
    body = re.sub(r"※［＃[^］]*］", "", body)   # 外字注記（該当字は捨てる）
    body = re.sub(r"［＃[^］]*］", "", body)     # 入力者注（傍点位置・字下げ等）
    body = re.sub(r"《[^》]*》", "", body)        # ルビ
    body = body.replace("｜", "")                 # ルビ開始位置記号

    return body


def split_sentences(body):
    """本文を文単位に分割し [(sentence_id, text), ...] を返す（1始まり採番）。"""
    sentences = []
    sid = 0
    for line in body.split("\n"):
        line = line.strip("　 \t")   # 行頭字下げ（全角/半角スペース）を除去
        if line == "":
            continue
        # 句点類の直後で分割（区切り文字は前の文に残す）
        for chunk in re.split(r"(?<=[。！？])", line):
            chunk = chunk.strip("　 \t")
            if chunk == "":
                continue
            sid += 1
            sentences.append((sid, chunk))
    return sentences


# ------------------------------------------------------------
# 2. GiNZA（term 抽出用）— 未導入なら None を返しフォールバック
# ------------------------------------------------------------
def load_ginza():
    try:
        import spacy
        nlp = spacy.load("ja_ginza")
        return nlp
    except Exception as e:  # noqa: BLE001  未導入・モデル無しはフォールバックへ
        print("[warn] GiNZA を読み込めませんでした（term抽出をスキップします）: %s" % e)
        return None


def extract_terms(nlp, texts):
    """各文からGiNZAで名詞（普通/サ変）を抽出。texts と同順の list[set(label)] を返す。"""
    if nlp is None:
        return [set() for _ in texts]
    result = []
    known = set(PERSON_DICT.keys()) | set(PERSON_DICT.values()) \
        | set(PLACE_DICT.keys()) | set(PLACE_DICT.values())
    known_lower = {s.lower() for s in known}   # 人物Kの小文字lemma("k")等を大小無視で除外
    # nlp.pipe でまとめて解析（決定論・高速）
    for doc in nlp.pipe(texts, batch_size=64):
        labels = set()
        for tok in doc:
            # 普通名詞・サ変可能名詞のみ（固有名詞は person/place の辞書側に任せる）
            if not tok.tag_.startswith("名詞"):
                continue
            if ("普通名詞" not in tok.tag_) and ("サ変" not in tok.tag_):
                continue
            lemma = (tok.lemma_ or tok.text).strip()
            if lemma == "" or lemma in TERM_STOPWORDS or lemma in known:
                continue
            if lemma.lower() in known_lower:   # "k"（人物Kの小文字化）等を除外
                continue
            # 単一文字（ひらがな/カタカナ/ラテン）は除外（ノイズ・人物Kの小文字対策）
            if len(lemma) == 1 and re.match(r"^[ぁ-んァ-ンa-zA-Z]$", lemma):
                continue
            labels.add(lemma)
        result.append(labels)
    return result


# ------------------------------------------------------------
# 3. ノード集合の構築（person/place=辞書, term=GiNZA）— specs §3
# ------------------------------------------------------------
def surfaces_in(text, surface_map):
    """surface_map(surface->label) のうち text に出現するものを {label: 出現回数} で返す。"""
    hits = defaultdict(int)
    for surface, label in surface_map.items():
        c = text.count(surface)
        if c > 0:
            hits[label] += c
    return hits


def build_sentence_nodes(sentences, term_sets):
    """各文のノード集合と、ノードの型・頻度・出現文集合を作る。

    戻り値:
      per_sentence: list[(sid, text, set(label))]  文ごとの登場ノード
      node_type:    dict[label -> "person"|"place"|"term"]
      node_freq:    dict[label -> 総出現回数]（表示用 frequency）
      node_df:      dict[label -> 出現文数]（Dice用）
    """
    per_sentence = []
    node_type = {}
    node_freq = defaultdict(int)
    node_df = defaultdict(int)

    for (sid, text), terms in zip(sentences, term_sets):
        labels = set()

        # person（辞書・出現回数も加算）
        for label, cnt in surfaces_in(text, PERSON_DICT).items():
            node_type[label] = "person"
            node_freq[label] += cnt
            labels.add(label)
        # place（辞書）。person に採られたラベルとは衝突しない想定
        for label, cnt in surfaces_in(text, PLACE_DICT).items():
            node_type.setdefault(label, "place")
            node_freq[label] += cnt
            labels.add(label)
        # term（GiNZA）。person/place で既に採られたラベルは type を上書きしない
        for label in terms:
            if label in node_type and node_type[label] in ("person", "place"):
                continue
            node_type.setdefault(label, "term")
            node_freq[label] += 1
            labels.add(label)

        for label in labels:
            node_df[label] += 1
        per_sentence.append((sid, text, labels))

    return per_sentence, node_type, node_freq, node_df


# ------------------------------------------------------------
# 4. 共起カウントとエッジ生成（Dice係数）— specs §4-5
# ------------------------------------------------------------
def build_edges(per_sentence, node_type, node_freq, node_df):
    """同一文共起から Dice重みのエッジを作り、エビデンスを付ける。"""
    # --- term をFREQ_MIN で足切り（person/place は残す）— specs §3.3 ---
    keep = set()
    for label, typ in node_type.items():
        if typ in ("person", "place") or node_freq[label] >= FREQ_MIN:
            keep.add(label)

    # --- 共起文数と、共起した文idを収集 ---
    cooc_df = defaultdict(int)                 # (a,b) -> 共起文数
    cooc_sents = defaultdict(list)             # (a,b) -> [sid,...]
    for sid, text, labels in per_sentence:
        present = sorted(l for l in labels if l in keep)
        for a, b in combinations(present, 2):  # a<b（ラベル文字列順で正規化）
            cooc_df[(a, b)] += 1
            cooc_sents[(a, b)].append(sid)

    # --- Dice計算・COOC_MIN足切り・エビデンス付与 ---
    sent_text = {sid: text for sid, text, _ in per_sentence}
    edges = []
    for (a, b), df_ab in cooc_df.items():
        if df_ab < COOC_MIN:
            continue
        dice = 2.0 * df_ab / (node_df[a] + node_df[b])
        weight = round(dice, 4)
        evidence = []
        for sid in sorted(cooc_sents[(a, b)])[:EVIDENCE_MAX]:
            text = sent_text[sid]
            start, end = _first_span(text, a, b)
            evidence.append({
                "sentence_id":     sid,
                "text_span_start": start,
                "text_span_end":   end,
                "sentence_text":   text,
            })
        edges.append({
            "a": a, "b": b, "weight": weight,
            "edge_type": "共起", "method": "co_occurrence",
            "evidence": evidence,
        })
    return keep, edges


def _first_span(text, a, b):
    """文中で先に出現する方のノード語のマッチ範囲を返す（無ければ 0,0）。"""
    positions = []
    for label in (a, b):
        for surface in _surfaces_of(label):
            idx = text.find(surface)
            if idx >= 0:
                positions.append((idx, idx + len(surface)))
    if not positions:
        return 0, 0
    positions.sort()
    return positions[0][0], positions[0][1]


def _surfaces_of(label):
    """ラベルに対応する原文表記の候補（person/placeは辞書の全surface、termはlabel自身）。"""
    surfaces = [s for s, lab in PERSON_DICT.items() if lab == label]
    surfaces += [s for s, lab in PLACE_DICT.items() if lab == label]
    if not surfaces:
        surfaces = [label]
    return surfaces


# ------------------------------------------------------------
# 5. JSON出力（import.php の契約に厳密準拠）— specs §6
# ------------------------------------------------------------
def write_json(keep, node_type, node_freq, edges):
    # --- ノードを決定論的に並べ ref を採番（頻度降順→ラベル昇順） ---
    labels = sorted(keep, key=lambda l: (-node_freq[l], l))
    ref_of = {}
    nodes_out = []
    for i, label in enumerate(labels, start=1):
        ref = "n%d" % i
        ref_of[label] = ref
        nodes_out.append({
            "ref":       ref,
            "label":     label,
            "node_type": node_type[label],
            "frequency": int(node_freq[label]),
        })

    # --- エッジを (source_ref, target_ref) で決定論的に並べる ---
    edges_out = []
    for e in edges:
        src, tgt = ref_of[e["a"]], ref_of[e["b"]]
        # ref番号順に source/target を正規化
        if int(src[1:]) > int(tgt[1:]):
            src, tgt = tgt, src
        edges_out.append({
            "source_ref": src,
            "target_ref": tgt,
            "edge_type":  e["edge_type"],
            "weight":     e["weight"],
            "method":     e["method"],
            "evidence":   e["evidence"],
        })
    edges_out.sort(key=lambda x: (int(x["source_ref"][1:]), int(x["target_ref"][1:])))

    os.makedirs(OUT_DIR, exist_ok=True)
    with open(NODES_JSON, "w", encoding="utf-8") as f:
        json.dump({"work": WORK_META, "nodes": nodes_out}, f,
                  ensure_ascii=False, indent=2)
        f.write("\n")
    with open(EDGES_JSON, "w", encoding="utf-8") as f:
        json.dump({"edges": edges_out}, f, ensure_ascii=False, indent=2)
        f.write("\n")

    return len(nodes_out), len(edges_out)


# ------------------------------------------------------------
# main
# ------------------------------------------------------------
def main():
    print("入力: %s" % SOURCE_TXT)
    body = load_and_clean(SOURCE_TXT)
    sentences = split_sentences(body)
    print("前処理後の文数: %d" % len(sentences))

    nlp = load_ginza()
    term_sets = extract_terms(nlp, [t for _, t in sentences])

    per_sentence, node_type, node_freq, node_df = build_sentence_nodes(sentences, term_sets)
    keep, edges = build_edges(per_sentence, node_type, node_freq, node_df)
    n_nodes, n_edges = write_json(keep, node_type, node_freq, edges)

    # 種別ごとの内訳（参考ログ）
    kinds = defaultdict(int)
    for label in keep:
        kinds[node_type[label]] += 1
    print("出力: nodes=%d (person=%d place=%d term=%d) / edges=%d"
          % (n_nodes, kinds["person"], kinds["place"], kinds["term"], n_edges))
    print("  -> %s" % NODES_JSON)
    print("  -> %s" % EDGES_JSON)
    if nlp is None:
        print("[note] GiNZA未導入のため term は0件です。手元で pip install -r pipeline/requirements.txt 後に再実行してください。")


if __name__ == "__main__":
    main()
