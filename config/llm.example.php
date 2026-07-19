<?php
// ============================================================
// llm.example.php — Azure OpenAI 接続設定の「見本（記入例）」
//   ★このファイルは中身がダミーなのでコミットしてよい（GitHubに上げる）。
//   ★実際に使うときは、このファイルを config/llm.php にコピーし、
//     本物のエンドポイント・APIキー・デプロイ名を記入する。
//     config/llm.php は .gitignore 済みで GitHub には上がらない（＝キーが漏れない）。
//
//   使い方（初回セットアップ）:
//     cp config/llm.example.php config/llm.php
//     # → config/llm.php を開いて 'YOUR_...' の部分を自分の値に書き換える
//
//   呼び出し側:  $llm = require __DIR__ . '/../config/llm.php';
//   （db.php と同じく __DIR__ 基準で読み込む前提。symlink越しでも解決できる）
// ============================================================

return [
  // Azure OpenAI リソースのエンドポイント（末尾スラッシュ不要）
  //   例: https://my-resource.openai.azure.com
  //   例: https://my-resource.services.ai.azure.com（Azure AI Foundry）
  //   ※Foundryの「プロジェクトURL」（.../api/projects/xxx が付いた形）を貼っても動く。
  //     query_llm.php がホスト部分だけを使い、/openai/deployments/... を自前で付ける。
  'endpoint'    => 'https://YOUR_RESOURCE_NAME.openai.azure.com',

  // APIキー（★秘密情報。絶対に公開しない）
  'api_key'     => 'YOUR_AZURE_OPENAI_API_KEY',

  // デプロイ名（Azureポータルでモデルをデプロイした際に付けた名前。モデル名とは別）
  'deployment'  => 'YOUR_DEPLOYMENT_NAME',

  // REST API のバージョン（json_object 出力に対応した GA 版）
  //   参考: https://learn.microsoft.com/en-us/azure/ai-foundry/openai/reference
  'api_version' => '2024-10-21',

  // 生成の温度（0＝毎回ほぼ同じ翻訳結果になり再現性が高い。翻訳用途なので低め固定）
  'temperature' => 0,

  // 上流API呼び出しのタイムアウト秒数（濫用・ハング対策）
  'timeout'     => 20,
];
