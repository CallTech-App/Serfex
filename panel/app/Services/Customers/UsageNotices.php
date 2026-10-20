<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\UsageNotice;
use App\Models\User;
use App\Services\Settings\Notifications;
use App\Services\Settings\OutgoingMail;

/**
 * Mails customers when their disk or monthly traffic reaches 85 % of the
 * package (warn) and when it is full (crit). Each level is mailed once,
 * and again only after usage fell below it (for traffic: next month).
 * Nothing is cut off: a full disk refuses new sites, mailboxes and
 * applications (Quotas), and sites keep running past the traffic limit.
 */
class UsageNotices
{
    public const KINDS = ['disk', 'traffic'];

    public function __construct(private readonly Quotas $quotas, private readonly OutgoingMail $mail) {}

    /**
     * Sends what is due. Returns how many messages went out.
     */
    public function check(): int
    {
        $sent = 0;
        $notices = UsageNotice::all()->groupBy(fn (UsageNotice $n) => $n->customer_id.':'.$n->kind);
        foreach (Customer::with('package')->get() as $customer) {
            $report = $this->quotas->report($customer);
            foreach (self::KINDS as $kind) {
                $level = $report[$kind]['level'];
                $had = $notices->get($customer->id.':'.$kind, collect())->pluck('level')->all();
                // Usage fell back: the warning can come again.
                $drop = match ($level) {
                    'ok' => $had,
                    'warn' => array_intersect($had, ['crit']),
                    default => [],
                };
                if ($drop !== []) {
                    UsageNotice::where('customer_id', $customer->id)->where('kind', $kind)->whereIn('level', $drop)->delete();
                }
                if ($level === 'ok' || in_array($level, $had, true)) {
                    continue;
                }
                $to = $this->recipients($customer);
                if ($to === [] || $this->mail->send($to, ...$this->message($customer, $kind, $level, $report[$kind])) !== null) {
                    continue; // tried again at the next run
                }
                UsageNotice::create(['customer_id' => $customer->id, 'kind' => $kind, 'level' => $level, 'sent_at' => now()]);
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * The customer's login addresses, or their contact address.
     *
     * @return list<string>
     */
    public function recipients(Customer $customer): array
    {
        $emails = User::where('customer_id', $customer->id)->whereNull('removed_at')->whereNull('invite_token')->pluck('email')->all();

        return $emails !== [] ? $emails : array_values(array_filter([$customer->email]));
    }

    /**
     * Subject and body of a warning.
     *
     * @param  array{used: int|null, limit: int|null, text: string}  $row
     * @return array{0: string, 1: string}
     */
    public function message(Customer $customer, string $kind, string $level, array $row): array
    {
        $pct = $row['limit'] ? (int) floor(($row['used'] ?? 0) / $row['limit'] * 100) : 0;
        $url = app(Notifications::class)->panelUrl().route('usage', ['sekme' => $kind === 'disk' ? 'disk' : 'trafik'], false);
        $package = $customer->package->name;
        [$subject, $lines] = match ([$kind, $level]) {
            ['disk', 'warn'] => [__('Disk alanınız dolmak üzere (%:pct)', ['pct' => $pct]), [
                __(':package paketinizin disk alanı dolmak üzere: :text kullanılıyor (%:pct).', ['package' => $package, 'text' => $row['text'], 'pct' => $pct]),
                __('Alan dolduğunda yeni site, posta kutusu ya da uygulama açılamaz; mevcutlar çalışmaya devam eder.'),
                __('Eski yedekleri, günlükleri ya da kullanılmayan dosyaları silerek yer açabilirsiniz.'),
            ]],
            ['disk', 'crit'] => [__('Disk alanınız doldu'), [
                __(':package paketinizin disk alanı doldu (:text).', ['package' => $package, 'text' => $row['text']]),
                __('Yeni site, posta kutusu ya da uygulama açılamaz; siteleriniz ve postanız çalışmaya devam ediyor.'),
                __('Yer açın ya da paketinizi yükseltmek için barındırma sağlayıcınıza yazın.'),
            ]],
            ['traffic', 'warn'] => [__('Aylık trafik sınırınıza yaklaştınız (%:pct)', ['pct' => $pct]), [
                __('Sitelerinizin bu ayki trafiği :package paketinizin sınırına yaklaştı: :text (%:pct).', ['package' => $package, 'text' => $row['text'], 'pct' => $pct]),
                __('Sınır aşılsa da siteleriniz kapanmaz. Trafik her ayın başında sıfırlanır.'),
            ]],
            default => [__('Aylık trafik sınırınızı aştınız'), [
                __('Sitelerinizin bu ayki trafiği :package paketinizin sınırını aştı (:text).', ['package' => $package, 'text' => $row['text']]),
                __('Siteleriniz çalışmaya devam ediyor; barındırma sağlayıcınız paketinizi yükseltmenizi isteyebilir. Trafik her ayın başında sıfırlanır.'),
            ]],
        };

        return [$subject, __('Merhaba :name,', ['name' => $customer->name])."\n\n".implode("\n", $lines)."\n\n".__('Ayrıntılar: :url', ['url' => $url])."\n"];
    }
}
