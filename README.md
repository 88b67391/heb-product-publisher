# HEB Product Publisher

一体化 WordPress 产品多站点分发插件。同一份插件在主站作为 **Hub**（编辑 → 翻译 → 分发），在各语言站作为 **Receiver**（接收推送 + 暴露站点信息），翻译通过 [OpenRouter](https://openrouter.ai/)。

更新通过本仓库的 **GitHub Releases** 直接推送到 WordPress 的原生更新流程——打一个 Release，所有站点自动在「仪表盘 → 更新」里出现可一键更新。

## 功能

- 主站后台在产品编辑页选中多个目标站点一键分发。
- 实时拉取目标站点的语言（locale）与分类（taxonomies/terms）。
- 每个目标站可独立勾选目标分类（支持预选匹配 + 全选/全不选）。
- 通过 OpenRouter 自动把标题、正文、摘要、ACF 文本翻译到目标语言；
  - 保留 HTML / URL / 数字 / 日期 / 型号编码 / ACF 选项键。
- 目标站 `/import-product` 接口：幂等（source_post_id + source_site）、缺失的 term slug 自动创建。
- 插件自带 GitHub Releases 自更新机制。

## 安装

把 `heb-product-publisher.zip`（见 Releases）上传到任意 WordPress 站点：主站 + 每一台语言站都装同一个插件。

### 关键配置

在每个站点的 `wp-config.php` 加上（任选）：

```php
// 每台站点（包括主站）作为「接收端」时的共享密钥。主站填在远端站点列表里必须对得上。
define( 'HEB_PUBLISHER_RECEIVER_SECRET', '<长随机串>' );

// 主站：OpenRouter API key。
define( 'HEB_PP_OPENROUTER_API_KEY', 'sk-or-...' );

// 自动更新：GitHub 仓库（也可在后台填写）。
define( 'HEB_PP_GITHUB_REPO',  'owner/heb-product-publisher' );
// 私有仓库或规避 API 速率限制时：
define( 'HEB_PP_GITHUB_TOKEN', 'ghp_xxx' );
```

具体说明、远端站点列表、翻译模型等在「设置 → HEB Publisher」里配。

## 开发 & 发布流程

本仓库根目录 = 插件根目录（`heb-product-publisher.php` 在根，不要多套一层）。

### 发布新版本

1. 改 `heb-product-publisher.php` header 的 `Version:` 和常量 `HEB_PP_VERSION`（保持一致）。
2. 提交并打 tag：

   ```bash
   git commit -am "release 2.1.1"
   git tag v2.1.1
   git push && git push --tags
   ```

3. GitHub Actions 会自动构建 `heb-product-publisher.zip` 并创建一个 Release，把 zip 作为资产上传。
4. 所有装了此插件的站点在 12 小时内（或用户点「立即检查更新」后）看到更新提示。

> 如果不想用 Actions，手动在 GitHub → Releases 发布同名 tag 即可，插件也会回退使用 GitHub zipball。

## 许可

GPL-2.0-or-later
