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
#   ★ノード抽出は「キュレーション辞書方式」:
#     頻度で名詞を自動採用すると汎用語（人間・力・家…）が紛れ込み、頻度では「罪」と「人間」を
#     区別できない。そこで person/place/actant の3辞書に載る語だけをノードにする
#     （＝開発時に小説を読んで選ぶ人手キュレーション。実行時はAI不使用の決定論・原則OK）。
#     単漢字の誤マッチ（「父」が「叔父」に含まれる等）は longest-match（長い語優先・マッチ位置消去）で防ぐ。
#
#   実行:  python pipeline/analyze.py   （GiNZA不要。標準ライブラリのみで動く）
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

WORK_META = {
    "title":  "こころ",
    "author": "夏目漱石",
    "source": "青空文庫 https://www.aozora.gr.jp/cards/000148/card773.html",
}

COOC_MIN     = 3   # エッジ化に必要な最小共起文数（偶発的共起の除去）
EVIDENCE_MAX = 5   # 1エッジあたりのエビデンス最大件数

# --- 人物辞書（person）: surface -> 正規化ラベル。表記ゆれもここで吸収 ---
#   家族（父・母・妻・叔父・兄）も人間アクターなので person に含める。
PERSON_DICT = {
    "私":       "私",
    "先生":     "先生",
    "Ｋ":       "K",
    "K":        "K",
    "奥さん":   "奥さん",
    "御嬢さん": "お嬢さん",
    "お嬢さん": "お嬢さん",
    "お父さん": "父",
    "父":       "父",
    "母":       "母",
    "妻":       "妻",
    "叔父":     "叔父",
    "兄":       "兄",
}

# --- 場所辞書（place）: surface -> ラベル ---
PLACE_DICT = {
    "鎌倉": "鎌倉",
    "東京": "東京",
}

# --- アクタント辞書（term）: 物語上の決定的アクタント/テーマ語だけを厳選（surface -> ラベル）---
#   ★頻度で自動採用しない。ここに載る語だけが term ノードになる（キュレーション）。
#   ★本文に実在する語のみ（ハルシネーション混入なし）。
ACTANT_DICT = {
    # 物（非人間アクター）
    "手紙": "手紙", "書物": "書物", "机": "机", "襖": "襖",
    "墓": "墓", "電報": "電報", "財産": "財産", "金": "金",
    # 出来事・行為
    "恋": "恋", "自殺": "自殺", "殉死": "殉死", "卒業": "卒業",
    "決心": "決心", "覚悟": "覚悟", "病気": "病気",
    # テーマ・概念
    "罪悪": "罪悪", "罪": "罪", "過去": "過去", "精神": "精神",
    "明治": "明治", "孤独": "孤独", "良心": "良心", "心": "心", "記憶": "記憶",
}

# 置換用のヌル文字（longest-match でマッチ位置を「消す」ときに使う。原文長は保つ）
_NULL = "\x00"


# ------------------------------------------------------------
# 1. 読み込みと前処理（青空文庫記法の除去）— specs §2
# ------------------------------------------------------------
def load_and_clean(path):
    """テキストを読み、ヘッダ/底本/見出し/ルビ/注記を除去して本文だけにする。"""
    with open(path, "r", encoding="utf-8") as f:
        text = f.read()
    text = text.replace("\r\n", "\n").replace("\r", "\n")
    lines = text.split("\n")

    # ヘッダ（記号説明ブロック）: 2本目の区切り線 "----..." までを捨てる
    dash_idx = [i for i, ln in enumerate(lines) if re.match(r"^-{5,}$", ln.strip())]
    if len(dash_idx) >= 2:
        lines = lines[dash_idx[1] + 1:]

    # 底本ブロック: "底本：" で始まる行以降を捨てる
    for i, ln in enumerate(lines):
        if ln.strip().startswith("底本："):
            lines = lines[:i]
            break

    # 見出し行（大/中/小見出し注記を含む行）を丸ごと除去
    heading_re = re.compile(r"［＃「[^」]*」は(?:大|中|小)見出し］")
    lines = [ln for ln in lines if not heading_re.search(ln)]

    body = "\n".join(lines)

    # 記法の除去（順序: 外字注記→一般注記→ルビ→ルビ開始記号）
    body = re.sub(r"※［＃[^］]*］", "", body)
    body = re.sub(r"［＃[^］]*］", "", body)
    body = re.sub(r"《[^》]*》", "", body)
    body = body.replace("｜", "")
    return body


def split_sentences(body):
    """本文を文単位に分割し [(sentence_id, text), ...] を返す（1始まり採番）。"""
    sentences = []
    sid = 0
    for line in body.split("\n"):
        line = line.strip("　 \t")
        if line == "":
            continue
        for chunk in re.split(r"(?<=[。！？])", line):
            chunk = chunk.strip("　 \t")
            if chunk == "":
                continue
            sid += 1
            sentences.append((sid, chunk))
    return sentences


# ------------------------------------------------------------
# 2. ノード検出（辞書3本・longest-match）— specs §3
# ------------------------------------------------------------
def build_entries():
    """全辞書を (surface, label, node_type) に展開し、長い surface 優先で並べる。

    長い語を先にマッチしてその位置を消すことで、短い語の二重カウント
    （「父」が「叔父」「お父さん」内で拾われる等）を防ぐ（longest-match）。
    """
    entries = []
    for surface, label in PERSON_DICT.items():
        entries.append((surface, label, "person"))
    for surface, label in PLACE_DICT.items():
        entries.append((surface, label, "place"))
    for surface, label in ACTANT_DICT.items():
        entries.append((surface, label, "term"))
    entries.sort(key=lambda e: -len(e[0]))
    return entries


def build_sentence_nodes(sentences, entries):
    """各文のノード集合と、ノードの型・頻度・出現文数を作る（辞書 longest-match）。

    戻り値:
      per_sentence: list[(sid, text, set(label))]
      node_type:    dict[label -> "person"|"place"|"term"]
      node_freq:    dict[label -> 総出現回数]
      node_df:      dict[label -> 出現文数]（Dice用）
    """
    per_sentence = []
    node_type = {}
    node_freq = defaultdict(int)
    node_df = defaultdict(int)

    for sid, text in sentences:
        work = text
        labels = set()
        for surface, label, ntype in entries:
            if surface == "":
                continue
            c = work.count(surface)
            if c > 0:
                work = work.replace(surface, _NULL * len(surface))  # マッチ位置を消す
                node_type.setdefault(label, ntype)
                node_freq[label] += c
                labels.add(label)
        for label in labels:
            node_df[label] += 1
        per_sentence.append((sid, text, labels))

    return per_sentence, node_type, node_freq, node_df


# ------------------------------------------------------------
# 3. 共起カウントとエッジ生成（Dice係数）— specs §4-5
# ------------------------------------------------------------
def build_edges(per_sentence, node_type, node_df):
    """同一文共起から Dice重みのエッジを作り、エビデンスを付ける。"""
    keep = set(node_type.keys())   # 辞書由来ノードは全採用（自動採用の足切りは無し）

    cooc_df = defaultdict(int)
    cooc_sents = defaultdict(list)
    for sid, text, labels in per_sentence:
        present = sorted(labels)
        for a, b in combinations(present, 2):
            cooc_df[(a, b)] += 1
            cooc_sents[(a, b)].append(sid)

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
    """ラベルに対応する原文表記の候補（3辞書の逆引き。無ければ label 自身）。"""
    surfaces = [s for s, lab in PERSON_DICT.items() if lab == label]
    surfaces += [s for s, lab in PLACE_DICT.items() if lab == label]
    surfaces += [s for s, lab in ACTANT_DICT.items() if lab == label]
    return surfaces if surfaces else [label]


# ------------------------------------------------------------
# 4. JSON出力（import.php の契約に厳密準拠）— specs §6
# ------------------------------------------------------------
def write_json(keep, node_type, node_freq, edges):
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

    edges_out = []
    for e in edges:
        src, tgt = ref_of[e["a"]], ref_of[e["b"]]
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
        json.dump({"work": WORK_META, "nodes": nodes_out}, f, ensure_ascii=False, indent=2)
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

    entries = build_entries()
    per_sentence, node_type, node_freq, node_df = build_sentence_nodes(sentences, entries)
    keep, edges = build_edges(per_sentence, node_type, node_df)
    n_nodes, n_edges = write_json(keep, node_type, node_freq, edges)

    kinds = defaultdict(int)
    for label in keep:
        kinds[node_type[label]] += 1
    # エッジのつかない孤立ノード（辞書に載せたが共起がCOOC_MIN未満）を報告
    connected = set()
    for e in edges:
        connected.add(e["a"])
        connected.add(e["b"])
    isolated = sorted(l for l in keep if l not in connected)

    print("出力: nodes=%d (person=%d place=%d term=%d) / edges=%d"
          % (n_nodes, kinds["person"], kinds["place"], kinds["term"], n_edges))
    if isolated:
        print("孤立ノード（エッジ0・%d件）: %s" % (len(isolated), " ".join(isolated)))
    print("  -> %s" % NODES_JSON)
    print("  -> %s" % EDGES_JSON)


if __name__ == "__main__":
    main()
