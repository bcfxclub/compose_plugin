<div align="center">
  <img src="docs/images/banner.png" alt="Compose Manager Plus" width="900">

![GitHub Repo stars](https://img.shields.io/github/stars/bcfxclub/compose_plugin)
![GitHub Downloads (all assets, latest release)](https://img.shields.io/github/downloads/bcfxclub/compose_plugin/latest/total)

</div>

# Compose Manager Plus 简体中文版

> 本仓库是 [mstrhakr/compose_plugin](https://github.com/mstrhakr/compose_plugin) 的**简体中文汉化版**。
> 汉化仅涉及界面文案、提示信息与文档，**未修改任何功能逻辑**。

Compose Manager 会在你的 unRAID 服务器上安装 Docker Compose CLI 插件，并提供一套完整的 Web 界面，让你直接在 unRAID 仪表盘里创建、运行、更新、备份和恢复 Compose 栈。它还支持 **Git 栈**（从仓库分支部署 compose 文件，可选定时检查变更或自动部署）。其他功能包括：每个栈的自动启动、定时镜像更新检查、集成终端、带通知的后台命令执行、可选的 UI 集成（隐藏由 Compose 管理的容器），以及用于测试和 CI 的配套工具。

## 汉化说明

- **已汉化**：主界面（栈列表、表头、右键菜单、弹窗与表单校验、状态提示）、栈编辑器各标签页与切换/保存提示、设置页（常规 / 更新 / 备份 / 日志 / 致谢，含 143 个界面标签与 39 段帮助说明）、凭据管理（校验结果、删除确认、GitHub 登录与续期）、仪表盘磁贴、Git 栈界面（分支与提交行、部署对话框）、插件管理器中的安装信息与变更日志分类。
- **未汉化**：源代码注释、系统日志（syslog）中的调试输出、`compose-git` 命令行工具的英文输出，以及部分深层 Git 诊断信息（如克隆/部署失败的具体原因）。保留英文是为了便于对照上游排查问题。
- **技术名词**：`Compose`、`Docker`、`Git`、`ENV`、`WebUI`、`Shell`、`YAML` 等保持原样；`stack` 统一译为「栈」，`profile` 译为「配置档」，`credential` 译为「凭据」。
- **状态值不翻译**：栈的运行状态在逻辑层仍是 `started` / `stopped` / `paused` / `partial`，只在显示层映射为中文，以免破坏判断逻辑。
- **上游同步**：本仓库基于上游 `v2026.10.27` 汉化。上游更新后重新汉化时，请对照本仓库的提交记录。
- **维护提示**：本仓库是 fork，GitHub 默认不运行工作流。如需自动发版，请在仓库 `Settings → Actions → General` 中确认已启用 Actions，并把 Workflow permissions 设为 **Read and write**。

## 界面截图

<details>
  <summary>点击展开截图</summary>

### 主界面

#### Compose 页面

![Compose Manager UI](docs/images/compose.png)

#### 仪表盘集成

![Dashboard Stacks](docs/images/dashboard.png)

### 栈编辑器

编辑器内置多个标签页用于管理 compose 栈：

#### Compose 标签页

![Editor - Compose Tab](docs/images/editor-composeFile.png)

#### Env 标签页

![Editor - Env Tab](docs/images/editor-env.png)

#### WebUI 标签页

![Editor - WebUI Labels Tab](docs/images/editor-webUI.png)

#### 设置标签页

![Editor - Settings Tab](docs/images/editor-settings.png)

#### 主题支持

![Editor - Compose Tab with the Azure theme applied](docs/images/theme-support.png)

### 设置页

#### 常规设置

![Settings - Main Tab](docs/images/settings-main.png)

#### 更新设置

![Settings - Updates Tab](docs/images/settings-updates.png)

#### 备份设置

![Settings - Backups Tab](docs/images/settings-backups.png)

#### 日志查看

![Settings - Logs Tab](docs/images/settings-logs.png)

</details>

## 功能特性

- **Docker Compose 集成** —— 安装 Docker Compose CLI 插件（默认 v5），并在 unRAID 上管理栈。
- **Web 界面管理** —— 直接在 unRAID 仪表盘中创建、编辑和管理 Compose 栈。
- **栈操作** —— 一键启动、停止、重启、更新、拉取/构建和删除栈（支持 profiles 与覆盖文件）。
- **右键菜单** —— 每个栈图标都有内容丰富的右键菜单，操作项会随栈状态变化。
- **容器右键菜单** —— 右键单个容器可打开 WebUI、控制台或日志，也可单独启动、停止、暂停、恢复、重启，无需影响整个栈。
- **自动更新** —— 按栈定时检查镜像更新（基于 SHA-256 摘要比对），支持可配置调度器、批量「立即运行」和一键全开/全关。
- **镜像固定** —— 使用 `@sha256:` 摘要引用的镜像会被自动识别为已固定，不会触发更新提醒。
- **后台执行** —— 任何栈操作（启动、停止、更新、拉取等）都可在后台派发，完成后弹出通知，界面会轮询等待锁释放。
- **可展开的栈详情** —— 点击栈行即可展开内嵌的容器表格，显示每个容器的更新状态、镜像来源/标签、网络、IP 与端口映射。
- **自动启动与关机处理** —— 按栈配置自动启动，可选强制重建、等待 Docker 就绪以及自定义超时，支持优雅关机。
- **可见性与过滤** —— 可将 Compose 管理的容器从原生 Docker 界面与仪表盘磁贴中隐藏，避免重复显示（具体行为随 unRAID 版本而异）。
- **备份与恢复** —— 支持手动与定时备份，并可在界面中选择性恢复。
- **Web 终端** —— 集成 ttyd 终端，实时显示带颜色的 compose 命令输出。
- **Profiles** —— 完整支持 Docker Compose profiles，每次操作可选择多个 profile。
- **外部路径** —— compose 文件与 env 文件可以放在默认项目目录之外（按栈配置外部 compose 路径与 env 路径）。
- **覆盖文件管理** —— 集中管理覆盖文件（`compose.override.yaml`、`compose.override.yml`、`docker-compose.override.yaml`、`docker-compose.override.yml`），并处理服务重命名。
- **栈编辑器** —— 全屏弹窗编辑器，含五个标签页：Compose（YAML 实时校验）、ENV、WebUI 标签（通过覆盖文件为每个服务设置图标/WebUI/Shell）、来源（compose 文件、外部路径与 Git 设置）、设置（名称、描述、图标 URL、WebUI URL 与默认 profile）。Ctrl+S 保存，Esc 关闭。
- **Git 栈** —— 可从 Git 仓库创建栈，或将现有栈迁移到 Git。可查看分支变更、部署指定提交，并可选接收通知或自动部署新提交。私有仓库支持 HTTPS 凭据与 SSH 部署密钥。
- **列自定义与布局** —— 可选择显示哪些栈列与服务列及其顺序；额外字段（如 SHA 片段、强制更新链接）会按你保存的布局显示。
- **Compose 文件发现** —— 自动识别四种标准 compose 文件名（`compose.yaml`、`docker-compose.yaml`、`compose.yml`、`docker-compose.yml`）。
- **保留文件名** —— 现有 compose 与覆盖文件名会被保留，升级不会自动重命名你的栈文件。
- **构建栈支持** —— 自动识别含 `build:` 段的栈，右键菜单与更新标签会相应变化（「构建」「构建并启动」「更新并重建」）。
- **间接栈支持** —— 通过「外部路径」注册指向阵列任意位置（项目目录之外）compose 文件的栈。
- **安全加固** —— 防止路径穿越、使用安全参数数组防止命令注入、全程 XSS 转义，并做严格的输入校验。
- **开发与测试工具** —— 单元测试（PHPUnit）、Shell 测试（BATS）、真实浏览器测试（Playwright）、静态分析（PHPStan）与 CI 工作流。

## 安装

### 通过插件管理器安装（推荐）

在 unRAID 中打开 **插件 → 安装插件**，粘贴以下地址：

```url
https://raw.githubusercontent.com/bcfxclub/compose_plugin/main/compose.manager.plg
```

点击「安装」后，插件会自动下载打包好的安装包并完成部署。

### 安装前须知

- 需要 PHP 8 与 Docker 服务（unRAID 6.12.0 及以上自带 PHP 8）。
- 已在 unRAID 7.2.3 及以上版本验证；更低版本未经验证。
- **本插件与官方 `Compose Manager Plus` 内部名称相同**，两者不能同时安装。若已安装官方版，请先在插件管理器中卸载，再安装本汉化版。项目目录结构完全一致，卸载重装后已有栈会被自动识别。

### 从官方版切换到汉化版

1. 打开 **插件** 页面，找到 `Compose Manager Plus`，点击卸载。
2. 使用上面的安装地址重新安装。
3. 打开 **设置 → Compose 管理器 Plus**，确认项目目录指向原来的路径（默认 `/boot/config/plugins/compose.manager/projects`），已有栈会原样出现。

> 更稳妥的做法：切换前先对所有栈执行「停止」，并备份一次项目目录。

## 配置

在 unRAID Web 界面进入 **设置 → Compose 管理器 Plus**。主要选项：

- **Compose 项目目录** —— 默认 `/boot/config/plugins/compose.manager/projects`。修改路径不会自动迁移已有项目。
- **在顶部菜单显示 Compose** —— 在 unRAID 顶部导航栏增加一个 Compose 标签页，并从 Docker 标签页中移除。
- **显示仪表盘磁贴** —— 在仪表盘上显示 Compose 栈概览磁贴。
- **自动启动时重建 / 等待 Docker 自动启动** —— 自动启动行为控制，另有「Docker 等待超时」与「栈启动超时」。
- **默认后台运行** —— 操作对话框默认勾选「后台运行」，完成后发送通知。
- **默认展开栈 / 仅展开运行中的栈** —— 控制页面加载时栈行的展开方式。
- **自动检查更新** —— 页面加载时自动检查镜像更新，可设置检查间隔。
- **在 Docker 页面隐藏 Compose 容器** —— 在 Docker 页面隐藏由 Compose 管理的容器（无标签页的 Docker 模式）。
- **在 Docker 仪表盘磁贴隐藏 Compose 容器** —— 在仪表盘 Docker 磁贴中隐藏由 Compose 管理的容器。
- **备份设置** —— 备份目标、保留数量与可选的定时备份。
- **调试日志** —— 将详细的 compose 命令输出记录到系统日志。

## 使用

### 创建栈

1. 进入 **Docker → Compose**（若启用了顶部菜单选项，则点击顶部 **Docker Compose**）。
2. 点击 **新建栈**。
3. 输入名称，可选填写描述。
4. 如需指定 compose 文件位置，展开 **高级** 并填写 **外部路径**。
5. 栈编辑器会自动打开，填入你的 `compose.yaml` 内容。
6. 点击 **启动** 运行该栈。

### Git 栈

在 **新建栈** 对话框中选择 **Git 仓库** 作为 compose 来源，填写仓库地址与 compose 文件路径。插件会把仓库克隆到阵列上；新栈在你点击 **拉取并重新部署** 之前不会部署。若要把现有栈转为 Git 栈，使用 **编辑栈 → 来源 → 将该栈迁移到 Git...**。

在栈菜单中可检查分支变更、部署最新提交或部署指定提交。在编辑器的 **来源** 标签页中，自动部署可设为 **关闭**、**通知** 或 **部署**，每 15 分钟检查一次。私有仓库可使用插件凭据设置中的 HTTPS 令牌或 SSH 部署密钥。请勿把密钥提交到仓库；栈的 `.env` 始终保存在其项目目录中。建议在仓库中修改文件而不是在服务器克隆中修改，因为本地克隆的改动必须在下次部署前处理掉。

### 管理栈

#### 右键菜单

右键（或左键）点击栈图标打开右键菜单，可用操作取决于栈的当前状态：

**运行中：**

- **WebUI** —— 在新标签页打开已配置的 WebUI 地址（若已设置）
- **停止（Down）** —— 停止并移除所有容器
- **停止（Stop）** —— 仅停止容器，不移除
- **重启** —— 重新创建容器，不拉取镜像
- **更新 / 更新并重建** —— 拉取最新镜像并重建（检测到更新时显示；构建栈显示为「更新并重建」）
- **强制更新 / 强制更新并重建** —— 即使未检测到更新也强制拉取并重建
- **检查更新** —— 立即对该栈执行 SHA-256 摘要检查
- **编辑栈** —— 打开完整编辑器
- **查看日志** —— 打开实时 `docker compose logs -f` 终端窗口
- **查看上次命令日志** —— 显示该栈上次命令的保存输出
- **删除栈**（运行中禁用）

**已停止：**

- **启动（Up）** —— 创建并启动所有容器
- **拉取 / 构建** —— 仅拉取或构建镜像，不启动
- **拉取并启动 / 构建并启动** —— 先拉取或构建，再启动
- **检查更新**、**编辑栈**、**查看日志**、**查看上次命令日志**、**删除栈**

所有支持 Docker Compose profiles 的操作，在定义了 profiles 时会显示 profile 选择器。

#### 容器右键菜单

展开栈行后，右键（或左键）点击容器图标：

- **WebUI** —— 打开容器 WebUI（需已配置且运行中）
- **控制台** —— 通过 ttyd 在容器内打开交互式终端
- **停止 / 暂停 / 重启** —— 管理单个容器
- **启动 / 恢复** —— 针对已停止或已暂停的容器
- **日志** —— 打开该容器的 `docker logs -f` 终端窗口

#### 操作对话框

确认栈操作时，对话框会列出所有容器及其当前镜像标签、更新状态，以及待更新项的 SHA-256 差异（当前 → 新）。**后台运行** 复选框可在无终端窗口的情况下派发命令，完成后会弹出通知。

### 自动更新

在 Compose 页面的工具栏进入 **自动更新** 区域，可配置每个栈的自动更新：

- 全局启用或关闭调度器
- 为每个栈设置计划（每天、每周等）
- 使用 **全部切换** 一次性启用/关闭所有栈
- 使用 **立即运行** 立即触发某个栈的更新检查
- 请求会串行处理，避免给服务器造成压力

执行器（`autoupdate_runner.php`）由 cron 调用，会先比对镜像摘要再拉取，因此只有真正有新镜像时才会更新。

### 镜像固定

如果 `compose.yaml` 中的服务使用带摘要的镜像（例如 `redis:7@sha256:abc123...`），Compose Manager 会自动将其标记为 **已固定**。已固定的容器在更新列显示青色图钉标记，并在更新检查时被跳过。

### 批量操作

工具栏提供 **全部启动**、**全部停止** 和 **全部更新** 按钮。**仅自动启动** 开关可把操作范围限定为启用了自动启动的栈。

### 自动启动

点击栈的自动启动开关即可启用。unRAID 阵列启动时，这些栈会自动启动，可选强制重建并配置等待超时。

## 文档

上游文档位于 [docs](docs/) 目录（英文）：

- [快速开始](docs/getting-started.md)
- [使用指南](docs/user-guide.md)
- [配置说明](docs/configuration.md)
- [Profiles](docs/profiles.md)
- [Git 栈](docs/git-stacks.md)

## 开发与测试

- 安装 PHP 依赖：`composer install`
- 初始化测试框架子模块：`git submodule update --init --recursive`
- 运行 PHPUnit：`composer test:unit`（或 `composer test`）
- 运行单个测试文件：`vendor/bin/phpunit tests/unit/ExampleTest.php`
- Shell 测试（BATS）位于 `tests/unit`，详见 [测试指南](docs/testing.md)
- 浏览器测试（Playwright）位于 `tests/e2e`，详见 [E2E 说明](tests/e2e/README.md)
- 静态分析：`composer run analyse`

完整开发环境搭建、测试运行与贡献指南见 `docs/development.md`。

## 发布流程

上游使用 GitHub Actions 全自动发版，本仓库沿用同一套流程：

1. 向 `main`（稳定）或 `dev`（测试）推送改动。
2. `tag-release.yml` 自动生成基于日期的版本标签（如 `v2026.10.31`），从提交信息生成变更日志并推送标签。
3. 标签触发 `build.yml`：在 Slackware 容器中构建 TXZ 包 → 创建 GitHub Release → 回写 `.plg` 的版本号与 MD5。

也可以在 Actions 页面手动触发 `build.yml` 做测试构建。

## 授权与致谢

本项目基于 **mstrhakr/compose_plugin**（原作者 **Nick Szittai / mstrhakr**）汉化，其上游为 **dcflachs/compose_plugin**（原作者 **dcflachs**）。功能实现与设计的全部功劳归两位原作者，本仓库仅做中文翻译。

请特别注意原项目的授权声明：

> Original material owned and licensable by **Nick Szittai (mstrhakr)** is offered under the [MIT License](LICENSE). This is **not a blanket license for the plugin**: inherited dcflachs/compose_plugin code and independently owned contributions are excluded, and upstream permission remains unresolved. The complete project is not yet cleared for unrestricted redistribution.

也就是说，原项目**尚未获准无限制再分发**。本仓库为个人学习与自用目的的中文汉化分支，不主张任何额外权利；如需再分发，请先取得原作者许可。详细授权与来源审计见 [LICENSING.md](source/compose.manager/licenses/LICENSING.md)，第三方组件遵循各自的[许可与声明](source/compose.manager/licenses/THIRD-PARTY-NOTICES.md)。

## 问题反馈

- 汉化相关问题（翻译错误、漏翻、术语不一致）：请在本仓库提 Issue。
- 功能性问题：请优先查阅[上游仓库](https://github.com/mstrhakr/compose_plugin/issues)与 [unRAID 论坛帖](https://forums.unraid.net/topic/197334-plugin-compose-manager-plus/)。
