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

  function renderGraph(elements) {
    var nodeCount = (elements.nodes || []).length;
    if (nodeCount === 0) {
      showMessage('表示できるデータがありません。');
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

    // グローバルに公開しておくと後続 issue（#6 クリック→詳細）から参照しやすい
    window.cy = cy;
  }

  // --- graph_data.php を取得して描画 ---
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
      var elements = (data && data.elements) ? data.elements : { nodes: [], edges: [] };
      renderGraph(elements);
    })
    .catch(function (err) {
      // 通信・パース失敗。詳細はコンソールへ、画面には簡潔なメッセージのみ。
      console.error('グラフデータの取得に失敗しました:', err);
      showMessage('グラフデータの取得に失敗しました。');
    });
})();
