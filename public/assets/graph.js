// ============================================================
// graph.js — graph_data.php を取得し Cytoscape.js でネットワーク図を描く
//   出典: idea.md §9 / Issue #5
//
//   方針（CLAUDE.md §5/§6）:
//     - 一次データ（nodes/edges＝事実）のみを描画する。エッジは「実線」で描く（ADR-004）。
//       二次データ（AI解釈）は破線で #8 で追加予定。凡例には両方の意味を明示する。
//     - APIが 401（未ログイン）を返したら login.php へ誘導する（idea.md §9）。
//     - このファイルは描画のみ。DB・認証・データ生成には一切関与しない。
// ============================================================

(function () {
  'use strict';

  var container = document.getElementById('cy');
  var messageEl = document.getElementById('graph-message');

  // ノード詳細サイドパネル（Issue #6）関連の要素
  var panelEl      = document.getElementById('node-panel');
  var panelTitleEl = document.getElementById('node-panel-title');
  var panelBodyEl  = document.getElementById('node-panel-body');
  var panelCloseEl = document.getElementById('node-panel-close');

  // graph_data.php が返した work_id を覚えておき、node_detail.php へ同じ作品を渡す。
  // （最新作品の自動選択が graph と detail でズレないようにするため）
  var currentWorkId = null;

  // 画面中央のメッセージ表示ヘルパー（読込中・データなし・エラー用）
  function showMessage(text) {
    if (!messageEl) return;
    if (text === null) {
      messageEl.style.display = 'none';
    } else {
      messageEl.textContent = text;
      messageEl.style.display = 'block';
    }
  }

  // node_type → 色（凡例と対応。未知の種別は other 色）
  var NODE_COLORS = {
    person: '#4c8bf5',
    place:  '#56b877'
  };
  var NODE_COLOR_OTHER = '#999999';

  // Cytoscape のスタイル定義
  function buildStyle() {
    return [
      {
        selector: 'node',
        style: {
          'background-color': function (ele) {
            return NODE_COLORS[ele.data('node_type')] || NODE_COLOR_OTHER;
          },
          'label': 'data(label)',
          'color': '#333',
          'font-size': '11px',
          'text-valign': 'bottom',
          'text-halign': 'center',
          'text-margin-y': 3,
          // frequency（出現頻度）でノードの大きさを変える。値が無くても最小サイズは確保
          'width':  'mapData(frequency, 0, 30, 18, 55)',
          'height': 'mapData(frequency, 0, 30, 18, 55)',
          'min-zoomed-font-size': 6
        }
      },
      {
        // 一次データ（統計的抽出）のエッジ = 実線（ADR-004）
        selector: 'edge',
        style: {
          'line-style': 'solid',
          'width': 'mapData(weight, 0, 1, 1, 6)',
          'line-color': '#9a9a9a',
          'curve-style': 'bezier',
          'label': 'data(edge_type)',
          'font-size': '9px',
          'color': '#777',
          'text-rotation': 'autorotate',
          'text-background-color': '#fafafa',
          'text-background-opacity': 0.8,
          'text-background-padding': 1,
          'min-zoomed-font-size': 6
        }
      },
      {
        // 二次データ（AI解釈）のエッジ = 破線。#8 で llm_groupings を描く際に使う（今は要素なし）
        selector: 'edge[provenance = "secondary"]',
        style: {
          'line-style': 'dashed',
          'line-color': '#b08fd6'
        }
      }
    ];
  }

  // ------------------------------------------------------------
  // ノード詳細サイドパネル（Issue #6）
  // ------------------------------------------------------------

  function closePanel() {
    if (panelEl) panelEl.hidden = true;
  }

  // 原文中の抽出箇所（[start, end)）を <mark> でハイライトしたノードを作る。
  // ★sentence_text は原文（外部由来）なので innerHTML を使わず textContent で組む（XSS対策）。
  //   span が原文長を超える・start>=end 等の不正時はハイライトせず全文だけ出す（安全側）。
  function buildEvidenceText(text, start, end) {
    var wrap = document.createElement('div');
    wrap.className = 'evidence-text';

    var len = text.length;
    var s = (typeof start === 'number') ? start : -1;
    var e = (typeof end === 'number') ? end : -1;
    var valid = s >= 0 && e > s && s <= len;

    if (!valid) {
      wrap.textContent = text;
      return wrap;
    }
    if (e > len) e = len;   // 終端が原文長を超える場合は末尾に丸める

    wrap.appendChild(document.createTextNode(text.slice(0, s)));
    var mark = document.createElement('mark');
    mark.textContent = text.slice(s, e);
    wrap.appendChild(mark);
    wrap.appendChild(document.createTextNode(text.slice(e)));
    return wrap;
  }

  // 隣接ノード1件ぶんのカード（エッジ＋相手ノード＋エビデンス）を組み立てる。
  function buildNeighborCard(nb) {
    var card = document.createElement('div');
    card.className = 'neighbor';

    var head = document.createElement('div');
    head.className = 'neighbor-head';

    var name = document.createElement('span');
    name.className = 'neighbor-name';
    name.textContent = (nb.neighbor && nb.neighbor.label) ? nb.neighbor.label : '(不明)';
    head.appendChild(name);

    // 関係の向き（out＝このノードが起点／in＝終点）
    var dir = document.createElement('span');
    dir.className = 'neighbor-dir';
    dir.textContent = (nb.direction === 'out') ? '→ への関係' : '← からの関係';
    head.appendChild(dir);

    if (nb.edge_type) {
      var type = document.createElement('span');
      type.className = 'neighbor-type';
      type.textContent = nb.edge_type;
      head.appendChild(type);
    }

    var weight = document.createElement('span');
    weight.className = 'neighbor-weight';
    // 抽出手法（method）と関係の強さ（weight）を検証用に併記
    var methodLabel = nb.method ? nb.method + ' / ' : '';
    weight.textContent = methodLabel + 'w=' + nb.weight;
    head.appendChild(weight);

    card.appendChild(head);

    // エビデンス（原文根拠）。ADR-003: 原文＋文番号・位置を辿れる形で見せる。
    var evList = nb.evidence || [];
    if (evList.length === 0) {
      var noEv = document.createElement('div');
      noEv.className = 'evidence-meta';
      noEv.textContent = 'エビデンスなし';
      card.appendChild(noEv);
    } else {
      evList.forEach(function (ev) {
        var evBox = document.createElement('div');
        evBox.className = 'evidence';
        evBox.appendChild(
          buildEvidenceText(ev.sentence_text || '', ev.text_span_start, ev.text_span_end)
        );

        var meta = document.createElement('div');
        meta.className = 'evidence-meta';
        var parts = [];
        if (ev.sentence_id !== null && ev.sentence_id !== undefined) {
          parts.push('第' + ev.sentence_id + '文');
        }
        if (ev.text_span_start !== null && ev.text_span_start !== undefined &&
            ev.text_span_end !== null && ev.text_span_end !== undefined) {
          parts.push('位置 ' + ev.text_span_start + '–' + ev.text_span_end);
        }
        meta.textContent = parts.join(' / ');
        evBox.appendChild(meta);

        card.appendChild(evBox);
      });
    }
    return card;
  }

  // node_detail.php のレスポンスをサイドパネルへ描画する。
  function renderPanel(data) {
    if (!panelEl || !panelBodyEl) return;
    var node = data.node || {};

    panelTitleEl.textContent = node.label || 'ノード詳細';

    // 中身を作り直す（前回表示のクリア）
    panelBodyEl.textContent = '';

    var meta = document.createElement('div');
    meta.className = 'node-panel-meta';
    var metaParts = [];
    if (node.node_type) metaParts.push('種別: ' + node.node_type);
    if (node.frequency !== null && node.frequency !== undefined) {
      metaParts.push('出現頻度: ' + node.frequency);
    }
    meta.textContent = metaParts.join(' / ');
    panelBodyEl.appendChild(meta);

    var neighbors = data.neighbors || [];
    var title = document.createElement('div');
    title.className = 'node-panel-section-title';
    title.textContent = '隣接ノードとエビデンス（' + neighbors.length + '件）';
    panelBodyEl.appendChild(title);

    if (neighbors.length === 0) {
      var empty = document.createElement('div');
      empty.className = 'node-panel-empty';
      empty.textContent = 'この作品内で接続する関係はありません。';
      panelBodyEl.appendChild(empty);
    } else {
      neighbors.forEach(function (nb) {
        panelBodyEl.appendChild(buildNeighborCard(nb));
      });
    }

    panelEl.hidden = false;
    panelEl.scrollTop = 0;
  }

  // 指定ノードの詳細を node_detail.php から取得してパネルへ表示する。
  function loadNodeDetail(nodeId) {
    var url = 'node_detail.php?node_id=' + encodeURIComponent(nodeId);
    if (currentWorkId !== null && currentWorkId !== undefined) {
      url += '&work_id=' + encodeURIComponent(currentWorkId);
    }
    fetch(url, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    })
      .then(function (res) {
        // 未ログイン（API 401）→ ログイン画面へ誘導（graph_data.php と同じ挙動）
        if (res.status === 401) {
          window.location.href = 'login.php';
          return null;
        }
        if (!res.ok) {
          throw new Error('node_detail.php returned HTTP ' + res.status);
        }
        return res.json();
      })
      .then(function (data) {
        if (data === null) return;   // 401でリダイレクト済み
        renderPanel(data);
      })
      .catch(function (err) {
        console.error('ノード詳細の取得に失敗しました:', err);
      });
  }

  // 閉じるボタン（存在すれば）
  if (panelCloseEl) {
    panelCloseEl.addEventListener('click', closePanel);
  }

  // elements で（再）描画する。フィルタ結果での再描画にも使う（Issue #7）。
  //   ★再描画時は前回の Cytoscape インスタンスを破棄してから作り直す（メモリリーク防止）。
  //   emptyMessage: ノードが0件のときに中央へ出す文言（省略時は既定メッセージ）。
  function renderGraph(elements, emptyMessage) {
    // 前回のグラフを破棄（初回は window.cy 未定義なので何もしない）
    if (window.cy) {
      try { window.cy.destroy(); } catch (e) { /* 破棄失敗は無視 */ }
      window.cy = null;
    }
    // ノード詳細パネルは古い作品の内容が残りうるので閉じる
    closePanel();

    var nodeCount = (elements.nodes || []).length;
    if (nodeCount === 0) {
      showMessage(emptyMessage || '表示できるデータがありません。');
      return;
    }
    showMessage(null);

    var cy = cytoscape({
      container: container,
      elements: elements,
      style: buildStyle(),
      layout: {
        name: 'cose',      // 力学配置。ノード数が少ないうちは見やすい
        animate: true,
        padding: 30,
        nodeRepulsion: 6000
      },
      wheelSensitivity: 0.2
    });

    // ノードをタップ→詳細サイドパネルを表示（Issue #6）
    cy.on('tap', 'node', function (evt) {
      loadNodeDetail(evt.target.id());
    });

    // 背景（ノード/エッジ以外）をタップ→パネルを閉じる
    cy.on('tap', function (evt) {
      if (evt.target === cy) {
        closePanel();
      }
    });

    // グローバルに公開しておくと後続 issue（#6 クリック→詳細）から参照しやすい
    window.cy = cy;
  }

  // ------------------------------------------------------------
  // 自然言語フィルタ（Issue #7）
  //   検索窓 → query_llm.php（AIがクエリJSONに翻訳）→ execute_query.php（DB実行）→ 再描画。
  //   ・AIは翻訳のみ。実行は QueryBuilder のホワイトリスト経由（サーバ側・ADR-005）。
  //   ・ここ（JS）はUIと再描画のみ担当。DB・認証・データ生成には関与しない。
  // ------------------------------------------------------------
  var queryFormEl   = document.getElementById('query-bar');
  var queryTextEl   = document.getElementById('query-text');
  var querySubmitEl = document.getElementById('query-submit');
  var queryResetEl  = document.getElementById('query-reset');
  var queryStatusEl = document.getElementById('query-status');

  // APIエラーコード → 利用者向けの日本語メッセージ
  var ERROR_MESSAGES = {
    llm_not_configured: 'AI接続が未設定です（config/llm.php を設定してください）。',
    llm_error:          'AIへの問い合わせに失敗しました。時間をおいて再度お試しください。',
    text_too_long:      '入力が長すぎます（200文字まで）。',
    empty_text:         '検索語を入力してください。',
    invalid_query:      'クエリを解釈できませんでした。',
    internal_error:     'サーバでエラーが発生しました。'
  };

  function setStatus(text, isError) {
    if (!queryStatusEl) return;
    queryStatusEl.textContent = text || '';
    queryStatusEl.classList.toggle('error', !!isError);
  }

  function setQueryBusy(busy) {
    if (querySubmitEl) querySubmitEl.disabled = busy;
    if (queryResetEl)  queryResetEl.disabled  = busy;
  }

  // レスポンスボディ（JSON）から error コードを取り出し、日本語メッセージにする。
  function messageFromError(data) {
    var code = (data && data.error) ? data.error : '';
    return ERROR_MESSAGES[code] || 'エラーが発生しました。';
  }

  // 401 は共通でログインへ誘導。それ以外の !ok はJSONを読んでメッセージ化して throw。
  function handleApiResponse(res) {
    if (res.status === 401) {
      window.location.href = 'login.php';
      return null;   // 呼び出し側は null で以降を中断
    }
    if (!res.ok) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        var err = new Error('api_error');
        err.userMessage = messageFromError(data);
        throw err;
      });
    }
    return res.json();
  }

  // 自然言語 → query_llm → execute_query → 再描画
  function runNaturalLanguageQuery(text) {
    setQueryBusy(true);
    setStatus('AIが問い合わせを解釈中…', false);

    fetch('query_llm.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ text: text, work_id: currentWorkId })
    })
      .then(handleApiResponse)
      .then(function (query) {
        if (query === null) return null;   // 401でリダイレクト済み
        // AIが返したクエリJSONを、そのまま execute_query.php に渡してDB実行する。
        // （実行の安全性はサーバ側 QueryBuilder のホワイトリストが担保する）
        return fetch('execute_query.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify(query)
        })
          .then(handleApiResponse)
          .then(function (data) {
            if (data === null) return;   // 401でリダイレクト済み
            var params = query.params || {};
            var edgeType = params.edge_type;
            var elements = (data && data.elements) ? data.elements : { nodes: [], edges: [] };

            if (edgeType) {
              renderGraph(elements, '「' + edgeType + '」に該当する関係はありませんでした。');
              setStatus('「' + edgeType + '」で絞り込み中（' +
                        (elements.edges || []).length + '件の関係）。', false);
            } else {
              // AIが関係種別を特定できなかった（全体表示相当）
              renderGraph(elements, '表示できるデータがありません。');
              setStatus('関係の種類を特定できませんでした。全体を表示しています。', false);
            }
          });
      })
      .catch(function (err) {
        console.error('自然言語フィルタに失敗しました:', err);
        setStatus(err.userMessage || 'エラーが発生しました。', true);
      })
      .then(function () {
        setQueryBusy(false);
      });
  }

  if (queryFormEl) {
    queryFormEl.addEventListener('submit', function (evt) {
      evt.preventDefault();
      var text = (queryTextEl ? queryTextEl.value : '').trim();
      if (text === '') {
        setStatus('検索語を入力してください。', true);
        return;
      }
      runNaturalLanguageQuery(text);
    });
  }
  if (queryResetEl) {
    queryResetEl.addEventListener('click', function () {
      if (queryTextEl) queryTextEl.value = '';
      setStatus('', false);
      loadInitialGraph();   // 全エッジを取り直して全体表示に戻す
    });
  }

  // --- graph_data.php を取得して描画（初回表示・全体表示に戻す 共通） ---
  function loadInitialGraph() {
    showMessage('読み込み中…');
    fetch('graph_data.php', {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    })
      .then(function (res) {
        // 未ログイン（API 401）→ ログイン画面へ誘導（idea.md §9 / #5 完了条件）
        if (res.status === 401) {
          window.location.href = 'login.php';
          return null;
        }
        if (!res.ok) {
          throw new Error('graph_data.php returned HTTP ' + res.status);
        }
        return res.json();
      })
      .then(function (data) {
        if (data === null) return;   // 401でリダイレクト済み
        // node_detail.php / query_llm.php へ渡すため、描画した作品の work_id を覚えておく（#6/#7）
        currentWorkId = (data && data.work_id !== undefined) ? data.work_id : null;
        var elements = (data && data.elements) ? data.elements : { nodes: [], edges: [] };
        renderGraph(elements);
      })
      .catch(function (err) {
        // 通信・パース失敗。詳細はコンソールへ、画面には簡潔なメッセージのみ。
        console.error('グラフデータの取得に失敗しました:', err);
        showMessage('グラフデータの取得に失敗しました。');
      });
  }

  loadInitialGraph();
})();
