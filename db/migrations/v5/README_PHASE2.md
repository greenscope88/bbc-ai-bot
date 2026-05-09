# SQL Safe Migration 5.0 - 第二階段（腳本實作版）

本文件定義 SQL Safe Migration 5.0 第二階段的實作邊界與 Step 1-A 範圍。

## 階段定位

- 第二階段是 **5.0 腳本實作版**。
- 第二階段 Step 1 只建立 **Plan Mode / Dry-run** 腳本能力。

## 強制限制

- 本階段不得執行 SQL。
- 本階段不得修改正式 DB。
- 本階段不得連線主機 B 做 SQL schema 變更。
- 本階段不得建立 Execute Mode。

## 允許的腳本能力（plan-only）

- 只允許讀取 proposal JSON。
- 只允許分析風險。
- 只允許產生 Plan Report。
- 只允許計算 hash。

## 後續規劃邊界

- Execute Mode 必須等第三階段測試通過後才可規劃。
- 所有腳本預設為 **plan-only / dry-run**。

