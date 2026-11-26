# WHMCS için Paymos

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | **Türkçe**

Paymos’un resmi WHMCS ödeme ağ geçidi modülüdür. Müşteri faturasına stablecoin ödeme seçeneği ekler ve transfer zincir üzerinde onaylandığında ödemeyi doğrudan WHMCS üzerinden işler — böylece provizyon, hizmet aktivasyonu ve mevcut tüm otomasyon kurallarınız diğer ödeme ağ geçitlerinde olduğu gibi aynen çalışır.

WHMCS bir e-ticaret vitrini değil, faturalama sistemidir ve bu modül tam olarak bu yapıya göre tasarlanmıştır. Sepetler yerine faturalar üzerinden çalışır ve ödemenin doğru hesaba bağlı kalması için WHMCS müşteri ID’sini iletir. Fatura, WHMCS tarafından oluşturulduğu para birimini korur; müşterinin ödediği kur ödeme anında kilitlenir, böylece faturalandırdığınız tutar eksiksiz olarak tahsil edilir.

[![İzleyin: 12 Resmi CMS Kripto Eklentisi — Tek Tıkla Bağlantı (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video tanıtımı (1:12, 6 dilde altyazılı):** [YouTube’da izleyin](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Gereksinimler

- WHMCS 8.13 ile 9.1 arası sürümler;
- `curl`, `hash`, `json` ve `openssl` uzantıları etkin PHP 7.4 veya üzeri;
- **HTTPS** üzerinden çalışan bir System URL — düz HTTP üzerinden bağlantı reddedilir;
- faturalama projesinin panelde açık olduğu bir Paymos hesabı.

Composer adımı veya harici çalışma zamanı bağımlılığı gerekmez: Paymos PHP SDK paketin içinde gelir. Yönetici arayüzü İngilizce, Rusça, Almanca, İspanyolca, Türkçe ve Basitleştirilmiş Çince dillerini içerir ve WHMCS yöneticisinin kullandığı dili otomatik olarak takip eder.

## Kurulum ve bağlantı

1. En güncel paketi [GitHub Releases](https://github.com/paymos-labs/whmcs/releases/latest) sayfasından indirin.
2. Arşivi WHMCS kök dizininize çıkarın — dosyaları `modules/gateways/` ve `includes/hooks/` dizinlerine yerleştirir.
3. **Configuration → Apps & Integrations → Payments → Paymos → Activate** yolunu izleyerek modülü etkinleştirin (WHMCS 8.13–9.1); bu işlem modülü aktif eder ve **Configuration → System Settings → Payment Gateways** altındaki ayar panelini açar.
4. Bu kurulumun faturalarını alacak projeyi Paymos panelinde açın; o an seçili olan proje otomatik olarak kullanılır.
5. **Configuration → System Settings → Payment Gateways** ekranındaki Paymos panelinde **Paymos’u bağla** (Connect Paymos) düğmesine tıklayın.
6. Açılan Paymos sekmesinde bağlantıyı onaylayın — sayfa bağlanan System URL adresini ve faturaların aktarılacağı projeyi gösterir; akışın hiçbir yerinde ikinci bir proje seçicisi bulunmaz.

Her satıcı aynı arşivi indirir; paketin içinde hiçbir API anahtarı, API gizli anahtarı, proje ID’si, webhook gizli anahtarı, OAuth belirteci veya cihaz kodu yer almaz. Tek başına hiçbir ödeme kabul etmez; bu kurulumu etkinleştiren adım 6. adımdaki onaydır.

Bu tek onay her iki ortamı da aynı anda yapılandırır. Paymos her ortam için tek aktif Ödeme anahtarınızı kullanır (yoksa yeni bir anahtar oluşturur) ve bu kurulum için Fatura webhook’unu kaydeder — mevcut bir webhook’u yalnızca callback URL’si, kategorisi ve projesi birebir eşleşiyorsa yeniden kullanır; aynı adreste bulunan farklı bir webhook’un üzerine yazmaz. Onay sırasında kullanılan cihaz belirteci kısa ömürlüdür ve hemen atılır; bundan sonraki her Merchant API çağrısı HMAC ile imzalanır.

Bu kurulum için kaydedilen callback adresi:

```text
https://your-store.example/modules/gateways/callback/paymosgateway.php
```

Bağlantıdan sonra modülde yalnızca üç ayar bulunur ve hiçbiri gizli kimlik bilgisi değildir: **Mod** (Sandbox veya Canlı), **Düğme metni** ve kurulumun bağlı olup olmadığını gösteren salt okunur **Bağlantı** satırı.

WHMCS’in **Convert To For Processing** seçeneğini **None** olarak bırakın. Paymos her faturayı faturanın kendi WHMCS para biriminde tahsil eder; **Convert To For Processing** faturayı başka bir para birimine dönüştürürse modül bir Paymos faturası oluşturmadan önce güvenli biçimde durur, **Billing → Gateway Log** kaydına `Currency mismatch: disable Convert To For Processing` yazar ve müşteriye bu ödeme yönteminin bu fatura için kullanılamayacağını bildirir.

## Gizli bilgilerin saklanması

Kimlik bilgileri ve geçici cihaz bağlantı durumu, WHMCS şifreleme karmasından türetilen bir anahtarla AES-256-GCM zarfı içinde şifrelenerek `tblconfiguration` tablosunda saklanır. Kayıtlı gizli bilgiler yönetici sayfasına hiçbir zaman geri yazdırılmaz ve bunları elle girmek için herhangi bir form alanı yoktur. System URL’yi değiştirir, bir Ödeme anahtarını iptal eder veya webhook gizli anahtarını yenilerseniz bağlantıyı yeniden kurmanız yeterlidir.

## Müşteriniz ne görür

Faturada **Paymos ile öde** (Pay with Paymos) düğmesi görünür ve bu düğme Paymos’un barındırılan ödeme sayfasını açar. Müşteriniz projenizde etkinleştirilen seçenekler arasından token ve ağı seçer, transferi gönderir ve durum değişimini sayfadan takip eder. Müşteriden Paymos hesabı istenmez ve ödeme sırasında e-posta adresi toplanmaz.

Aynı tutar, para birimi, proje ve ortamda hâlihazırda aktif bir Paymos faturasına sahip olan bir WHMCS faturası mevcut faturayı yeniden kullanır; böylece sayfayı yenilemek hesabınızda yarım kalmış faturalar biriktirmez. Tutarı değiştirdiğinizde bir sonraki tıklama yeni bir referansla taze bir Paymos faturası oluşturur — fiyat düzeltmesi yapıp kalem güncellediğiniz durumlar için idealdir.

## WHMCS faturasına ne olur

Faturanın durumunu yalnızca tek bir olay sınıfı değiştirir. `invoice.paid` veya `invoice.paid_over` geldiğinde modül WHMCS’in yerleşik `addInvoicePayment` işlevini çağırır, bakiye sıfırlanır, fatura **Paid** durumuna geçer ve gerisini WHMCS devralır.

Bunun öncesindeki her şey faturaya değil **Billing → Gateway Log** bölümüne kaydedilir: transfer zincir onayları toplarken `invoice.confirming`, tutarın yalnızca bir kısmı ulaştığında `invoice.underpaid_waiting`, ödeme süresi kapandığında ise `invoice.expired` ve `invoice.cancelled`. Fatura basitçe Unpaid olarak kalır ve müşteri tekrar ödeme yapabilir. Bu bilinçli bir tercihtir: bir hosting faturasının beş ara durumdan geçmesi, bunlar için hiç yazılmamış askıya alma ve hatırlatma kurallarını yanlışlıkla tetikleyebilir.

Tüm bunlardan önce her nihai olay iki kez doğrulanır — önce imza, ardından Merchant API’den yeniden çekilen güncel fatura saklanan anlık kayıtla karşılaştırılır. Bunu iki ek koruma izler: Hâlihazırda Paid olarak işaretlenmiş bir WHMCS faturası sonraki tüm tamamlama veya geri alma olaylarını yok sayar; böylece bir `paid` olayını izleyen `paid_over` olayı ikinci bir ödeme kaydedemez ve müşteriye fazladan bakiye kredisi tanımlayamaz. Tutarı artık faturayla eşleşmeyen bir ödeme olayı ise uygulanmak yerine manuel inceleme için günlüğe kaydedilir.

## Yenilemeler ve yinelenen hizmetler

Paymos kayıtlı ödeme yöntemi tutmaz ve otomatik çekim yapmaz. Bir blokzincir transferi sahibi tarafından başlatılır; hiç kimse takvime bağlı olarak cüzdandan otomatik çekim yapamaz. Bu nedenle WHMCS tarafından oluşturulan yenileme faturaları da ilk fatura gibi ödenir — müşteriniz faturayı açar ve ödemeyi gönderir.

Yinelenen faturalandırmaya dair diğer her şey aynı kalır. WHMCS faturayı kendi döngüsünde oluşturmaya, hatırlatmaları göndermeye ve kurallarınıza göre askıya alma veya sonlandırma işlemlerini çalıştırmaya devam eder; ödeme yalnızca müşteri transferi gönderdiğinde işlenir. İş modeliniz yenileme gününde karttan sessizce otomatik çekim yapmaya dayanıyorsa, kurulum öncesinde bu sınırı net biçimde bilmek önemlidir.

## Canlıya geçmeden önce Sandbox’ta test edin

**Mod** ayarını Sandbox yapın, müşteri olarak bir test faturası açın ve barındırılan ödeme sayfasına geçin. Sandbox ödemeleri bir zamanlayıcıyla otomatik onaylanmaz — ilgili faturayı Paymos panelinde açın ve provasını yapmak istediğiniz sonucu elle tetikleyin. Gerçek bir transferin üreteceği olayların birebir aynısı yayınlanır; dolayısıyla Sandbox’ta doğruladığınız akış Canlı ortamda da aynı şekilde çalışır.

Ardından **Mod** ayarını Canlı (Live) olarak değiştirin. Her iki ortam tek bağlantıda yapılandırıldığı için ikinci bir kurulum veya yeniden bağlantı gerekmez.

## Webhook teslimatı ve cron güvenlik ağı

Webhook’lar `X-Webhook-Signature` başlığı ile imzalanır — `t={timestamp},v1={hmac_hex}`, HMAC-SHA256, sabit zamanlı karşılaştırma ve gizli anahtar rotasyonu sırasında her iki anahtarı da kabul etme desteğiyle. Her teslimat yeniden denemeler boyunca değişmeyen kararlı bir olay ID’si taşır ve modül bu ID’leri saklar; bu sayede tekrar gönderilen bir olay iki kez uygulanmadan 200 yanıtı döndürür.

Kaçırılan teslimatlar WHMCS cron göreviyle yakalanır. `AfterCronJob` üzerinde, en fazla on dakikada bir çalışacak şekilde modül son 24 saate ait en fazla 50 ödenmemiş Paymos faturasını alır, her birini API’den sorgular ve bir webhook’un izlediği aynı doğrulama hattından geçirir — ters doğrulama, tutar koruması ve zaten ödenmiş fatura koruması aynen geçerlidir. İkinci bir `AfterCronJob` kancası ise bekleyen Paymos iadelerini mutabık kılar.

## İadeler

Modül, Paymos Merchant API (`/v1/refunds`) üzerinden sunucu tarafından hesaplanan iki iade modunu destekler: `full` (tam fatura iadesi) ve `overpayment` (`paid_over` faturasındaki fazla ödenen tutarın iadesi). Fonlar, fatura ödenirken Paymos tarafından kaydedilen doğrulanmış `first_sender` adresine yeni bir giden blokzincir işlemi olarak gönderilir — alıcı cüzdan adresi elle girilmez ve protokol keyfi kısmi iade tutarlarını desteklemez.

**Refund Invoice Payments** iznine sahip yöneticilerin WHMCS’te ödenmiş bir Paymos faturası üzerinde iki giriş noktası vardır:

- doğrudan yönetici fatura görünümünde render edilen **Paymos iadeleri** (Paymos refunds) paneli (`AdminInvoicesControlsOutput`): geçerli `full` ve (`paid_over` faturalar için) `overpayment` işlemlerini sunar, geçersiz veya tamamlanmış modları gizler ve işlem durumu ile olası istek hatalarını gösterir;
- WHMCS faturasının yerleşik **Refund** sekmesi (`paymosgateway_refund`): bir `full` iade talebi gönderir. WHMCS’te yerleşik bir “iade sürüyor” durumu bulunmadığından `paymosgateway_refund` erken muhasebe borcu yazmadan isteği kaydeder ve WHMCS faturasını yalnızca Paymos iadesi `completed` durumuna ulaştığında günceller.

Bir `full` iadesi (webhook veya cron mutabakatı ile) `completed` durumuna ulaştığında, modül bunu WHMCS’in yerleşik `refundInvoicePayment` işleviyle kaydeder (ağ geçidine ikinci bir istek göndermeden). **Invoice Immutability** etkinleştirilmiş WHMCS 9.1 üzerinde WHMCS, giden iade işlemini ve eşleşen Alacak Dekontunu (`invoice_billing_adjustment_credit`) atomik olarak oluşturup faturayı sıfır bakiye ile **Refunded** durumuna geçirir; WHMCS 8.13’te (veya değişmezlik kapalıyken) standart iade işlemini kaydeder ve faturayı **Refunded** olarak işaretler.

Arka plan mutabakatı WHMCS `AfterCronJob` (`includes/hooks/paymosgateway_reconcile.php`) üzerinde çalışır ve sürmekte olan iade taleplerini Merchant API ile senkronize eder. Sandbox modunda, Canlı moda geçmeden önce nihai iade sonuçlarını (`completed`, `failed`, `cancelled`) simüle edebilirsiniz.

## Sorun giderme

**Bağlantı başlamıyor.** System URL `https://` ile başlamalıdır. WHMCS bunu Configuration → System Settings → General Settings altından bildirir ve modül onay yanıtını bu adresle karşılaştırır; dolayısıyla buradaki bir uyuşmazlık daha önce çalışan bir bağlantının da durmasına yol açar.

**Müşteri ödeme yaptı ancak fatura hâlâ Unpaid görünüyor.** Önce Gateway Log’a bakın. `Manual review` kaydının iki nedeni vardır ve kayıt metni bunları ayırır: ya Paymos faturası oluşturulduktan sonra fatura tutarı değişmiştir ya da eski Paymos faturası hâlihazırda ödenmiş, hâlâ ödenebilir veya okunamaz durumdayken ödenecek tutar, mod veya proje değişmiştir. İkinci durumda yeni fatura oluşturulmaz ve müşteriden sizinle iletişime geçmesi istenir; önce Paymos panelinde eski faturayı kontrol edin. Her iki durumu da elle mutabık kılın. `Snapshot mismatch`, kayıtlı anlık görüntü ile canlı faturanın uyuşmadığı anlamına gelir ve bu uyuşmazlık giderilmeden hiçbir işlem uygulanmaz.

**Müşteri “Bu ödeme yöntemi bu fatura için kullanılamaz.” mesajını görüyor.** **Billing → Gateway Log** kaydını kontrol edin. `Currency mismatch: disable Convert To For Processing` görünüyorsa **Configuration → System Settings → Payment Gateways** sayfasını açın ve Paymos üzerindeki **Convert To For Processing** ayarını **None** yapın — Paymos faturanın kendi para biriminde tahsilat yapar ve WHMCS tutarı farklı bir işlem para birimine çevirdiğinde Paymos faturası oluşturmadan önce işlemi güvenli biçimde durdurur.

**Gateway Log’da hiçbir kayıt yok.** Bu durumda callback sunucunuza hiç ulaşmamıştır. Yukarıdaki callback URL’sine ağınızın dışından istek gönderin: adres HTTP kimlik doğrulaması, IP izin listesi ve yönlendirme olmadan erişilebilir olmalıdır; yönlendirmeler takip edilmez.

- [Dokümantasyon](https://paymos.io/tr/docs/cms-whmcs)
- [Kaynak kodu](https://github.com/paymos-labs/whmcs)
- [Değişiklik günlüğü](CHANGELOG.md)
- [Destek](mailto:support@paymos.io)
