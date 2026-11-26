# Paymos für WHMCS

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | **Deutsch** | [Türkçe](README.tr.md)

Offizielles WHMCS-Zahlungsmodul für Paymos. Es ergänzt die Kundenrechnung um eine Stablecoin-Zahlungsoption und bucht den Zahlungseingang nach der On-Chain-Bestätigung direkt über WHMCS ein — Provisioning, Dienstaktivierung und alle bestehenden Automatisierungsregeln greifen dadurch genau wie bei jedem anderen Gateway.

WHMCS ist ein Abrechnungssystem und kein klassischer Onlineshop, und dieses Modul ist exakt auf diese Struktur ausgelegt. Es arbeitet mit Rechnungen statt Warenkörben und reicht die WHMCS-Kunden-ID durch, sodass jede Zahlung dem richtigen Konto zugeordnet bleibt. Die Rechnung behält die von WHMCS festgelegte Währung; der Wechselkurs wird im Moment der Zahlung fixiert, sodass exakt der fakturierte Betrag abgerechnet wird.

[![Video ansehen: 12 offizielle CMS-Krypto-Plugins — 1-Klick-Verbindung (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video-Walkthrough (1:12, Untertitel in 6 Sprachen):** [Auf YouTube ansehen](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Voraussetzungen

- WHMCS 8.13 bis 9.1;
- PHP 7.4 oder neuer mit den Erweiterungen `curl`, `hash`, `json` und `openssl`;
- eine System-URL über **HTTPS** — Verbindungen über unverschlüsseltes HTTP werden abgelehnt;
- ein Paymos-Konto mit dem gewünschten Abrechnungsprojekt im Dashboard geöffnet.

Es ist weder Composer noch eine zusätzliche Laufzeitabhängigkeit erforderlich: Das Paymos PHP SDK ist bereits im Paket enthalten. Der Administrationsbereich wird auf Englisch, Russisch, Deutsch, Spanisch, Türkisch und Chinesisch (vereinfacht) ausgeliefert und richtet sich nach der Sprache des WHMCS-Administrators.

## Installation und Verbindung

1. Laden Sie das aktuelle Paket von [GitHub Releases](https://github.com/paymos-labs/whmcs/releases/latest) herunter.
2. Entpacken Sie das Archiv in das Stammverzeichnis Ihrer WHMCS-Installation — dabei werden Dateien unter `modules/gateways/` und `includes/hooks/` abgelegt.
3. Öffnen Sie **Configuration → Apps & Integrations → Payments → Paymos → Activate** (WHMCS 8.13–9.1), um das Modul zu aktivieren und dessen Einstellungsbereich unter **Configuration → System Settings → Payment Gateways** aufzurufen.
4. Öffnen Sie im Paymos-Dashboard das Projekt, das die Rechnungen dieser Installation empfangen soll; dieses aktuelle Projekt wird automatisch übernommen.
5. Klicken Sie im Paymos-Bereich unter **Configuration → System Settings → Payment Gateways** auf **Paymos verbinden** (Connect Paymos).
6. Bestätigen Sie die Verbindung im geöffneten Paymos-Tab — dort werden die verknüpfte System-URL und das Zielprojekt angezeigt; eine zweite Projektauswahl gibt es im gesamten Ablauf nicht.

Jeder Händler lädt dasselbe Archiv herunter — ohne API-Schlüssel, API-Secret, Projekt-ID, Webhook-Secret, OAuth-Token oder Gerätecode. Allein für sich nimmt es keine Zahlungen an; erst die Bestätigung in Schritt 6 schaltet diese Installation frei.

Diese eine Bestätigung richtet beide Umgebungen gleichzeitig ein. Paymos verwendet für jede Umgebung Ihren einzelnen aktiven Payment-Schlüssel (oder erstellt einen, falls noch keiner vorhanden ist) und registriert den Invoice-Webhook für diese Installation: Ein bestehender Webhook wird nur wiederverwendet, wenn Callback-URL, Kategorie und Projekt exakt übereinstimmen; ein anderer bereits vorhandener Webhook an derselben Adresse bleibt unangetastet und wird nicht überschrieben. Das kurzlebige Geräte-Token aus der Freigabe wird sofort verworfen; jeder weitere Aufruf der Merchant API erfolgt HMAC-signiert.

Registrierte Callback-Adresse für diese Installation:

```text
https://your-store.example/modules/gateways/callback/paymosgateway.php
```

Danach bietet das Modul genau drei Einstellungen — keine davon ist ein Zugangsdatum: **Modus** (Sandbox oder Live), **Buttontext** sowie eine schreibgeschützte **Verbindung**-Zeile, die anzeigt, ob die Installation verbunden ist.

Belassen Sie die WHMCS-Option **Convert To For Processing** auf **None**. Paymos rechnet jede Rechnung in ihrer ursprünglichen WHMCS-Währung ab; wandelt **Convert To For Processing** eine Rechnung in eine andere Währung um, bricht das Modul vor dem Anlegen einer Paymos-Rechnung fail-closed ab, protokolliert `Currency mismatch: disable Convert To For Processing` unter **Billing → Gateway Log** und zeigt dem Kunden an, dass diese Zahlungsart für diese Rechnung nicht verfügbar ist.

## Speicherung von Geheimnissen

Zugangsdaten und temporärer Geräte-Verbindungsstatus werden in einem authentifizierten AES-256-GCM-Umschlag in `tblconfiguration` gespeichert, abgeleitet aus dem WHMCS-Encryption-Hash. Gespeicherte Geheimnisse werden niemals in der Administrationsoberfläche ausgegeben, und es gibt keine Eingabefelder dafür. Verbinden Sie das Modul erneut, nachdem Sie die System-URL geändert, einen Payment-Schlüssel widerrufen oder ein Webhook-Secret rotiert haben.

## Was Ihre Kunden sehen

Auf der Rechnung erscheint die Schaltfläche **Mit Paymos bezahlen** (Pay with Paymos), die den gehosteten Paymos-Checkout öffnet. Ihre Kunden wählen dort Token und Netzwerk aus den für Ihr Projekt freigeschalteten Optionen, senden die Transaktion und verfolgen den Status direkt auf der Seite. Ein Paymos-Konto ist nicht erforderlich, und beim Checkout wird keine E-Mail-Adresse abgefragt.

Hat eine Rechnung bereits eine aktive Paymos-Rechnung mit demselben Betrag, derselben Währung, demselben Projekt und derselben Umgebung, wird sie wiederverwendet — das Neuladen der Seite erzeugt also keine halbfertigen Rechnungen in Ihrem Konto. Ändert sich der Betrag, legt der nächste Klick eine neue Paymos-Rechnung mit neuer Referenz an — praktisch, wenn Sie eine Position preislich korrigiert haben.

## Was mit der WHMCS-Rechnung passiert

Nur eine einzige Ereignisklasse ändert die Rechnung selbst. Bei `invoice.paid` oder `invoice.paid_over` ruft das Modul die WHMCS-eigene Funktion `addInvoicePayment` auf, der offene Betrag wird ausgeglichen, die Rechnung wechselt auf **Paid** und WHMCS übernimmt alle weiteren Schritte.

Alle vorherigen Ereignisse werden stattdessen im **Billing → Gateway Log** festgehalten: `invoice.confirming`, während die Transaktion Bestätigungen sammelt, `invoice.underpaid_waiting` bei einer Teilzahlung sowie `invoice.expired` und `invoice.cancelled`, wenn das Zahlungsfenster abgelaufen ist. Die Rechnung bleibt auf Unpaid und der Kunde kann sie erneut bezahlen. Das ist bewusst so gestaltet: Würde eine Hosting-Rechnung fünf Zwischenstatus durchlaufen, würden Sperr- und Mahnregeln auslösen, die nie dafür vorgesehen waren.

Vor jeder Buchung wird jedes Abschlussereignis doppelt geprüft — erst die Signatur, anschließend wird die Rechnung über die Merchant API erneut abgerufen und gegen den gespeicherten Snapshot abgeglichen. Darauf folgen zwei weitere Schutzmechanismen: Eine bereits als Paid markierte WHMCS-Rechnung ignoriert jedes spätere Abschluss- oder Herabstufungsereignis, sodass ein `paid` gefolgt von `paid_over` keine zweite Zahlung bucht und dem Kunden kein Überzahlungsguthaben gutschreibt. Stimmt der Betrag eines bezahlten Ereignisses nicht mehr mit der Rechnung überein, wird der Vorgang zur manuellen Prüfung protokolliert statt automatisch angewendet.

## Verlängerungen und wiederkehrende Dienste

Paymos speichert keine Zahlungsmethoden und führt keine automatischen Abbuchungen durch. Eine Blockchain-Transaktion wird immer vom Eigentümer aktiv ausgelöst und lässt sich nicht nach Zeitplan einziehen. Eine von WHMCS erzeugte Verlängerungsrechnung bezahlt Ihr Kunde daher genauso wie die erste: Rechnung öffnen und überweisen.

Alles andere an der wiederkehrenden Abrechnung bleibt unverändert. WHMCS erstellt die Rechnungen weiter im gewohnten Zyklus, versendet Erinnerungen und führt Sperrungen sowie Kündigungen nach Ihren Regeln aus; der Zahlungseingang wird verbucht, sobald der Kunde überweist. Hängt Ihr Geschäftsmodell an stillen automatischen Kartenabbuchungen am Verlängerungstag, sollten Sie diese Grenze vor der Installation kennen.

## Vor dem Live-Gang in der Sandbox testen

Stellen Sie **Modus** auf Sandbox, öffnen Sie als Kunde eine Testrechnung und klicken Sie bis zum gehosteten Checkout durch. Sandbox-Zahlungen bestätigen sich nicht per Timer — öffnen Sie die Rechnung im Paymos-Dashboard und lösen Sie das gewünschte Ergebnis gezielt aus. Es werden dieselben Ereignisse erzeugt wie bei einer echten Transaktion, sodass Ihr Sandbox-Test dem Live-Verhalten eins zu eins entspricht.

Wechseln Sie danach bei **Modus** auf Live. Beide Umgebungen wurden mit einer einzigen Verbindung eingerichtet; eine zweite Einrichtung oder erneute Verbindung ist nicht nötig.

## Zustellung und Cron-Sicherheitsnetz

Webhooks werden mit `X-Webhook-Signature` signiert — `t={timestamp},v1={hmac_hex}`, HMAC-SHA256, zeitkonstanter Vergleich und gleichzeitige Toleranz beider Secrets während einer Rotation. Jede Zustellung trägt eine stabile Ereignis-ID, die bei Wiederholungen erhalten bleibt; das Modul speichert diese IDs, sodass eine erneute Zustellung mit 200 beantwortet, aber nicht doppelt gebucht wird.

Verpasste Zustellungen fängt der WHMCS-Cron auf. Über `AfterCronJob`, höchstens einmal alle zehn Minuten, holt das Modul bis zu 50 unbezahlte Paymos-Rechnungen der letzten 24 Stunden ab, fragt jede über die API ab und führt sie durch denselben Pfad wie einen Webhook — Rückwärtsprüfung, Betragsprüfung und Schutz bereits bezahlter Rechnungen gelten unverändert. Ein zweiter `AfterCronJob`-Hook gleicht ausstehende Paymos-Erstattungen ab.

## Erstattungen

Das Modul unterstützt zwei serverseitig berechnete Erstattungsmodi über die Paymos Merchant API (`/v1/refunds`): `full` (vollständige Rechnungserstattung) und `overpayment` (Erstattung des Überschusses bei einer `paid_over`-Rechnung). Die Auszahlung erfolgt als neue ausgehende Blockchain-Transaktion ausschließlich an die verifizierte `first_sender`-Adresse, die Paymos beim Zahlungseingang gespeichert hat — eine manuelle Eingabe von Empfängeradressen gibt es nicht, und frei wählbare Teilbeträge werden vom Protokoll nicht unterstützt.

Administratoren mit der Berechtigung **Refund Invoice Payments** haben bei einer bezahlten Paymos-Rechnung in WHMCS zwei Einstiegspunkte:

- das Panel **Paymos-Erstattungen** (Paymos refunds) direkt in der Admin-Rechnungsansicht (`AdminInvoicesControlsOutput`), das die verfügbaren Aktionen `full` und (bei `paid_over`-Rechnungen) `overpayment` anbietet, nicht anwendbare oder bereits abgeschlossene Modi ausblendet und den Vorgangsstatus samt möglicher Fehlermeldungen zeigt;
- den nativen WHMCS-Rechnungsreiter **Refund** (`paymosgateway_refund`), der eine `full`-Erstattungsanforderung auslöst. Da WHMCS keinen nativen Status „Erstattung läuft“ kennt, speichert `paymosgateway_refund` die Anfrage ohne vorzeitige Buchungsbelastung und aktualisiert die WHMCS-Rechnung erst, sobald die Paymos-Erstattung den Status `completed` erreicht.

Sobald eine `full`-Erstattung (per Webhook oder Cron-Abgleich) `completed` erreicht, verbucht das Modul sie über die WHMCS-Funktion `refundInvoicePayment` (ohne eine zweite Anfrage an das Gateway zu senden). Unter WHMCS 9.1 mit aktivierter **Invoice Immutability** legt WHMCS atomar die ausgehende Erstattungstransaktion sowie die zugehörige Gutschrift (`invoice_billing_adjustment_credit`) an und setzt die Rechnung mit Saldo null auf **Refunded**; unter WHMCS 8.13 (oder ohne Unveränderlichkeit) wird die reguläre Erstattungstransaktion gebucht und die Rechnung als **Refunded** markiert.

Der Hintergrundabgleich läuft über den WHMCS-Hook `AfterCronJob` (`includes/hooks/paymosgateway_reconcile.php`) und synchronisiert laufende Erstattungsanforderungen mit der Merchant API. Im Sandbox-Modus lassen sich abschließende Erstattungsergebnisse (`completed`, `failed`, `cancelled`) vor dem Wechsel auf Live simulieren.

## Fehlerbehebung

**Die Verbindung startet nicht.** Die System-URL muss mit `https://` beginnen. WHMCS liefert sie unter Configuration → System Settings → General Settings, und das Modul gleicht die Bestätigungsantwort dagegen ab — eine Abweichung an dieser Stelle unterbricht daher auch eine zuvor funktionierende Verbindung.

**Der Kunde hat bezahlt, die Rechnung steht aber weiterhin auf Unpaid.** Prüfen Sie zuerst das Gateway Log. `Manual review` hat zwei mögliche Ursachen, die direkt im Logtext unterschieden werden: Entweder hat sich der Rechnungsbetrag nach dem Anlegen der Paymos-Rechnung geändert, oder fälliger Betrag, Modus bzw. Projekt haben sich geändert, während die alte Paymos-Rechnung bereits bezahlt, noch bezahlbar oder nicht abrufbar war. Im zweiten Fall wird keine neue Rechnung erzeugt und der Kunde gebeten, Sie zu kontaktieren — prüfen Sie zuerst die alte Rechnung im Paymos-Dashboard. Beide Fälle werden manuell abgeglichen. `Snapshot mismatch` bedeutet, dass gespeicherter Eintrag und Live-Rechnung voneinander abweichen; bis zur Klärung wird nichts gebucht.

**Der Kunde sieht „Diese Zahlungsart ist für diese Rechnung nicht verfügbar.“** Prüfen Sie **Billing → Gateway Log**. Steht dort `Currency mismatch: disable Convert To For Processing`, öffnen Sie **Configuration → System Settings → Payment Gateways** und setzen Sie **Convert To For Processing** bei Paymos auf **None** — Paymos rechnet in der ursprünglichen Rechnungswährung ab und bricht vor der Rechnungserstellung fail-closed ab, wenn WHMCS den Betrag in eine andere Verarbeitungswährung umrechnet.

**Im Gateway Log steht überhaupt nichts.** Dann ist der Callback nie angekommen. Rufen Sie die oben genannte Callback-URL von außerhalb Ihres Netzwerks auf: Sie muss ohne HTTP-Authentifizierung, ohne IP-Allowlist und ohne Weiterleitung erreichbar sein, da Weiterleitungen nicht verfolgt werden.

- [Dokumentation](https://paymos.io/de/docs/cms-whmcs)
- [Quellcode](https://github.com/paymos-labs/whmcs)
- [Änderungsprotokoll](CHANGELOG.md)
- [Support](mailto:support@paymos.io)
