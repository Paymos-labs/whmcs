# Paymos WHMCS 官方稳定币支付插件

[English](README.md) | **简体中文** | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Paymos 官方 WHMCS 支付网关模块。它在客户账单页面添加稳定币支付选项（支持 USDT/USDC 收款），链上转账确认后通过 WHMCS 原生流程自动入账——您已配置的自动开通、服务激活及所有自动化规则都会与其他支付网关一样照常触发。

WHMCS 本质上是计费系统而非电商购物车，本模块正是为此架构打造。它直接基于账单（而非购物车）运行，并透传 WHMCS 客户 ID，确保每笔付款准确归属到对应账户。账单保留 WHMCS 开具时的原始币种；汇率在客户付款瞬间锁定，您开出多少金额，最终就结算多少金额。

[![观看演示：12 款官方 CMS 稳定币支付插件 — 一键连接 (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **视频演示（1:12，支持 6 种语言字幕）：** [在 YouTube 上观看](https://www.youtube.com/watch?v=VMLUGVumYkg)

## 环境要求

- WHMCS 8.13 至 9.1；
- PHP 7.4 及以上版本，启用 `curl`、`hash`、`json` 和 `openssl` 扩展；
- 使用 **HTTPS** 的系统网址（System URL）——拒绝通过明文 HTTP 连接；
- 已注册 Paymos 账户，并在控制台中打开用于收款的计费项目。

无需执行 Composer 安装，也无需解析额外运行时依赖：安装包已内置 Paymos PHP SDK。后台管理界面内置英文、俄文、德文、西班牙文、土耳其文和简体中文六种语言，会自动跟随 WHMCS 管理员的界面语言。

## 安装与一键连接

1. 前往 [GitHub Releases](https://github.com/paymos-labs/whmcs/releases/latest) 下载最新安装包。
2. 将压缩包解压到 WHMCS 根目录——它会自动释放 `modules/gateways/` 和 `includes/hooks/` 目录下的文件。
3. 打开 **Configuration → Apps & Integrations → Payments → Paymos → Activate**（WHMCS 8.13–9.1）激活模块，并进入 **Configuration → System Settings → Payment Gateways** 中的 Paymos 设置面板。
4. 在 Paymos 控制台中打开需要接收此站点账单的项目，系统会自动选用当前打开的项目。
5. 在 **Configuration → System Settings → Payment Gateways** 的 Paymos 面板中点击**连接 Paymos**（Connect Paymos）。
6. 在新打开的 Paymos 授权标签页中点击批准——页面会展示待绑定的 System URL 以及对应的收款项目，全程不会出现第二个项目选择器。

所有商家下载的安装包完全一致，内部不包含任何 API 密钥、API Secret、项目 ID、Webhook 密钥、OAuth 令牌或设备码。安装包本身无法接收付款，只有完成第 6 步授权后，当前站点才会正式激活。

一次授权即可同时配置沙盒与正式两套环境。Paymos 会分别为两个环境复用您当前唯一生效的 Payment 密钥（若尚未创建则自动生成），并注册当前站点的账单 Webhook——仅当已有 Webhook 的回调地址、类别与项目全部匹配时才会复用，如果该地址下已存在其他用途的 Webhook，则保持原样不会覆盖。授权产生的设备令牌为短期有效，使用后立即丢弃；此后的每一次 Merchant API 请求均采用 HMAC 签名。

当前站点注册的回调地址如下：

```text
https://your-store.example/modules/gateways/callback/paymosgateway.php
```

连接完成后，模块仅保留三项非敏感设置：**模式**（沙盒或正式模式）、**按钮文字**（Button Text），以及只读的**连接**（Connection）状态栏，用于显示当前安装是否已连接。

请务必将 WHMCS 的 **Convert To For Processing** 选项保持为 **None**。Paymos 始终按账单在 WHMCS 中的原始币种结算；如果 **Convert To For Processing** 将账单转换成了其他币种，模块会在创建 Paymos 账单前采取安全阻断（fail-closed），在 **Billing → Gateway Log** 中记录 `Currency mismatch: disable Convert To For Processing`，并提示客户此支付方式不适用于该账单。

## 密钥安全存储

所有凭证及临时设备授权状态均使用 AES-256-GCM 加密封装后存入 `tblconfiguration`，加密密钥派生自 WHMCS 的 encryption hash。已保存的密钥绝不会回显到后台管理页面，界面中也不提供手动输入密钥的表单字段。如果更改了 System URL、吊销了 Payment 密钥或轮换了 Webhook 密钥，只需重新执行一键连接即可。

## 客户付款体验

账单页面会显示**通过 Paymos 付款**（Pay with Paymos）按钮，点击后进入 Paymos 托管收银台。客户可在您项目启用的范围内自选稳定币与公链网络，发起转账并在页面实时查看状态变化。客户无需注册 Paymos 账户，收银台也不会收集其邮箱。

如果某张 WHMCS 账单在相同金额、币种、项目及环境下已有生效中的 Paymos 账单，系统会直接复用，刷新页面不会在您的账户里留下大量未完成的冗余账单。修改账单金额后，下次点击会以新的参考编号生成一张全新的 Paymos 账单——非常适合调价后重新开具账单项目的场景。

## WHMCS 账单状态流转

只有一类事件会直接变更 WHMCS 账单状态。收到 `invoice.paid` 或 `invoice.paid_over` 后，模块会调用 WHMCS 原生的 `addInvoicePayment`，账单余额清零，状态转为 **Paid**，后续开通与自动化流程全部交由 WHMCS 接管。

在此之前的所有中间事件都只记录在 **Billing → Gateway Log**，不会改写账单状态——包括链上等待确认的 `invoice.confirming`、部分到账的 `invoice.underpaid_waiting`，以及支付窗口关闭的 `invoice.expired` 和 `invoice.cancelled`。账单保持 Unpaid，客户随时可以再次付款。这样设计是有意为之：如果主机账单频繁经过五个中间状态，很可能会误触发原本并非为此设计的停机或催缴规则。

在执行入账前，所有终态事件都会经过双重校验——先验证签名，再向 Merchant API 反向拉取实时账单并与本地快照比对。随后还有两道防护：已标记为 Paid 的 WHMCS 账单会忽略后续任何完成或降级事件，防止先 `paid` 后 `paid_over` 导致重复入账并给客户多记预存款；若已付款事件的金额与账单不再一致，系统不会自动入账，而是记录下来供人工审核。

## 续费与周期性服务

Paymos 不保存客户支付方式，也不会发起自动扣款。区块链转账由持币人主动推送，无法按计划自动划扣。因此，WHMCS 生成的续费账单与首笔账单的付款方式一致——客户打开账单并完成支付即可。

周期性计费的其他所有环节均不受影响。WHMCS 仍按原有周期出账、发送催缴提醒，并按您的规则执行暂停或终止服务；付款则在客户发起转账时入账。如果您的业务强依赖到期日静默自动扣卡，请在安装前明确这一技术边界。

## 上线前在沙盒模式测试

将**模式**切换为沙盒（Sandbox），以客户身份打开一张测试账单并跳转至托管收银台。沙盒付款不会按定时器自动确认——请在 Paymos 控制台中打开该账单，手动触发您想演练的结果。系统会发送与真实链上转账完全一致的事件，您在沙盒中验证过的流程就是正式环境的真实表现。

验证无误后将**模式**切换为正式模式（Live）。由于单次一键连接已同时完成两套环境的配置，切换时无需二次设置，也无需重新连接。

## Webhook 送达与定时任务兜底

所有 Webhook 均通过 `X-Webhook-Signature` 请求头签名，格式为 `t={timestamp},v1={hmac_hex}`，使用 HMAC-SHA256 签名验证与恒定时间比对，并在密钥轮换期间同时兼容新旧两个密钥。每次推送都携带在重试期间保持不变的稳定事件 ID，模块会持久化记录已处理的 ID，因此重复投递的事件会直接返回 200 而不会被重复入账。

遗漏的推送由 WHMCS 定时任务（cron）兜底补齐。在 `AfterCronJob` 钩子上（频率不超过每 10 分钟一次），模块最多拉取过去 24 小时内的 50 笔未付 Paymos 账单，逐笔向 API 查询最新状态，并沿用与 Webhook 完全相同的处理路径——反向校验、金额防护和已付防护全部生效。另一个 `AfterCronJob` 钩子则负责对账处理中的 Paymos 退款。

## 退款

模块通过 Paymos Merchant API（`/v1/refunds`）支持两种由服务端计算金额的退款模式：`full`（账单全额退款）与 `overpayment`（退还 `paid_over` 账单的多付部分）。退款资金会作为一笔新的链上转出交易，严格原路退回 Paymos 在该账单付款时记录并校验的 `first_sender` 地址——无需也不允许手动输入收款钱包地址，协议不支持自定义任意金额的部分退款。

拥有 **Refund Invoice Payments** 权限的管理员在已付款的 Paymos 账单上有两个退款入口：

- 管理员账单详情页直接渲染的 **Paymos 退款**（Paymos refunds）面板（`AdminInvoicesControlsOutput`）：提供可用的 `full` 以及（针对 `paid_over` 账单的）`overpayment` 操作，自动隐藏不适用或已完成的模式，并展示操作状态与请求错误信息；
- WHMCS 原生账单 **Refund** 标签页（`paymosgateway_refund`）：提交 `full` 全额退款意图。由于 WHMCS 没有原生的“退款处理中”状态，`paymosgateway_refund` 仅登记退款请求，不会提前记入账务扣减，待 Paymos 退款达到 `completed` 状态后再更新 WHMCS 账单。

当 `full` 退款通过 Webhook 或定时任务对账确认达到 `completed` 时，模块会调用 WHMCS 原生的 `refundInvoicePayment` 完成记账（不会再次向网关发起请求）。在开启 **Invoice Immutability** 的 WHMCS 9.1 上，WHMCS 会原子化创建退回交易与对应的贷项通知单（Credit Note，`invoice_billing_adjustment_credit`），并将账单余额归零、状态置为 **Refunded**；在 WHMCS 8.13（或未启用不可变账单时）则会记录标准退款交易并将账单标记为 **Refunded**。

后台对账通过 WHMCS 的 `AfterCronJob`（`includes/hooks/paymosgateway_reconcile.php`）运行，将进行中的退款意图与 Merchant API 同步。在沙盒模式下，您可以在切换至正式模式前模拟各种退款终态（`completed`、`failed`、`cancelled`）。

## 故障排查

**点击连接后无法启动。** System URL 必须以 `https://` 开头。WHMCS 在 Configuration → System Settings → General Settings 中上报该地址，模块会将授权响应与之比对，因此地址不一致也会导致原本正常的连接失效。

**客户已付款，但账单仍显示 Unpaid。** 请先查看 Gateway Log。日志中的 `Manual review` 有两种成因，具体原因会直接写在日志文本中：一是创建 Paymos 账单后 WHMCS 账单金额发生了变动；二是在旧 Paymos 账单已付款、仍可支付或无法读取时，应付金额、模式或项目发生了变更。第二种情况下系统不会开具新账单，而是提示客户联系您，请先在 Paymos 控制台核查旧账单。这两种情况均需人工对账。若看到 `Snapshot mismatch`，说明本地快照记录与实时账单不一致，在两者核对一致前系统不会自动入账。

**客户看到“此支付方式不适用于该账单。”（This payment method cannot be used for this invoice.）** 请检查 **Billing → Gateway Log**。如果日志显示 `Currency mismatch: disable Convert To For Processing`，请打开 **Configuration → System Settings → Payment Gateways**，将 Paymos 的 **Convert To For Processing** 设为 **None**——Paymos 按账单原生币种计费，一旦 WHMCS 将金额转换为其他处理币种，模块会在创建 Paymos 账单前直接安全阻断。

**Gateway Log 中完全没有任何记录。** 说明回调请求根本没有到达您的服务器。请从外网直接访问上方的回调地址：该地址必须无需 HTTP 身份认证、不受 IP 白名单拦截，且不能发生跳转（重定向不会被跟随）。

- [开发文档](https://paymos.io/zh-hans/docs/cms-whmcs)
- [源码仓库](https://github.com/paymos-labs/whmcs)
- [更新日志](CHANGELOG.md)
- [技术支持](mailto:support@paymos.io)
