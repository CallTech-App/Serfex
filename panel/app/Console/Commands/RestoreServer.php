<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Models\BackupItem;
use App\Models\BackupTarget;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Backups\Backups;
use App\Services\Backups\Restores;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Rebuilds a server from a whole-server backup, on a fresh Servik install
 * that has the backup listed (imported from the import folder or found on
 * a target in Yedekler):
 *
 *   --panel  loads the panel's database and application key from the
 *            backup, replacing this panel's (sites, customers, settings...)
 *   --sites  sets every site of the backup up on this server and restores
 *            its files, databases, mail, DNS and certificates
 *
 * Install the same database servers and set up mail first, so databases
 * and mailboxes have somewhere to go.
 */
#[Signature('servik:restore-server {key? : The backup to restore from} {--panel : Load the panel database and settings} {--sites : Restore every site} {--force : Do not ask}')]
#[Description('Rebuild this server from a whole-server backup')]
class RestoreServer extends Command
{
    public function handle(AgentClient $agent, Backups $backups, Restores $restores): int
    {
        $key = $this->argument('key');
        if ($key === null) {
            $this->table([__('Anahtar'), __('Tarih'), __('Kaynak'), __('Siteler'), __('Panel')], Backup::with('items')->whereIn('scope', ['server', 'panel'])->usable()->latest()->limit(30)->get()
                ->map(fn (Backup $b) => [$b->key, $b->created_at->format('Y-m-d H:i'), $b->source_host ?? __('bu sunucu'), $b->items->where('name', '!=', 'panel')->count(), $b->items->contains('name', 'panel') ? __('evet') : __('hayır')])->all());
            $this->line(__('Bir anahtar ve --panel ve/veya --sites ile yeniden çalıştırın.'));

            return self::SUCCESS;
        }
        $backup = Backup::with('items', 'target')->where('key', $key)->first();
        if ($backup === null || ! $backup->isUsable()) {
            $this->error(__(':key yedeği burada listelenmiyor. Önce Yedekler sayfasından içe aktarın.', ['key' => $key]));

            return self::FAILURE;
        }
        if (! $this->option('panel') && ! $this->option('sites')) {
            $this->error(__('--panel, --sites ya da ikisini birden seçin.'));

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm($this->option('panel')
            ? __('Panelin veritabanı yedektekiyle değiştirilecek. Devam edilsin mi?')
            : __('Yedekteki her site bu sunucudakinin üzerine geri yüklenecek. Devam edilsin mi?'))) {
            return self::FAILURE;
        }

        if ($this->option('panel')) {
            $panel = $backup->items->firstWhere('name', 'panel');
            if ($panel === null) {
                $this->error(__('Yedekte panel arşivi yok.'));

                return self::FAILURE;
            }
            if (! $this->restorePanel($agent, $backups, $backup, $panel)) {
                return self::FAILURE;
            }
            // The backup describes itself again in the loaded database.
            $backup = Backup::with('items', 'target')->where('key', $key)->first();
        }

        if ($this->option('sites')) {
            $failed = 0;
            foreach ($backup->items->where('name', '!=', 'panel')->where('status', 'succeeded') as $item) {
                $this->line("▸ {$item->name}");
                $restore = $restores->start($item, $item->restorable(), false, null, provision: true, queue: false);
                if (is_string($restore)) {
                    $this->warn("  {$restore}");
                    $failed++;

                    continue;
                }
                $restores->run($restore);
                $restore->refresh();
                if ($restore->status !== 'succeeded') {
                    $this->warn('  '.$restore->error);
                    $failed++;

                    continue;
                }
                foreach ((array) ($restore->result['skipped'] ?? []) as $part => $why) {
                    $this->line('  '.__(':part atlandı: :why', ['part' => $part, 'why' => $why]));
                }
                $this->info('  '.__('geri yüklendi'));
            }
            if ($failed > 0) {
                $this->warn(trans_choice(':count site geri yüklenemedi.', $failed));

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Loads panel.sql.gz into the panel's database with psql and takes the
     * old application key from panel.env, so encrypted settings (target
     * keys, the backup passphrase, the AI key) can be read.
     */
    private function restorePanel(AgentClient $agent, Backups $backups, Backup $backup, BackupItem $panel): bool
    {
        // Every archive comes to this server first: the targets this panel
        // knows are replaced by the loaded ones.
        if (! $backup->onServer() && ($why = $backups->fetch($backup)) !== null) {
            $this->error($why);

            return false;
        }
        $dir = storage_path('app/private/backup-work');
        if (! is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        $dump = $dir.'/restore-'.Str::random(12).'.sql.gz';
        try {
            $this->download($agent, $backup->key, 'panel.sql.gz', $dump);
            $env = $this->read($agent, $backup->key, 'panel.env');
        } catch (AgentException $e) {
            @unlink($dump);
            $this->error(__('Panel arşivi okunamadı: :reason', ['reason' => $e->reason]));

            return false;
        }
        // What this panel knows about the backup, written back after the
        // load (the dump was taken while the backup was running).
        $self = $backup->only(['key', 'scope', 'type', 'trigger', 'remote', 'encrypted', 'remote_prefix', 'size', 'seconds', 'run', 'source_host', 'started_at', 'finished_at', 'created_at']);
        $passphrase = $backup->passphrase;
        $items = $backup->items->map(fn (BackupItem $i) => $i->only(['name', 'size', 'parts', 'incremental', 'skipped', 'warnings', 'meta', 'status', 'error']))->all();
        $targetName = $backup->target?->name;

        $c = config('database.connections.'.config('database.default'));
        $this->line('▸ '.__('Panel veritabanı yükleniyor'));
        $result = Process::env(['PGPASSWORD' => (string) ($c['password'] ?? '')])->timeout(3600)->input((string) gzdecode((string) file_get_contents($dump)))
            ->run(['psql', '-v', 'ON_ERROR_STOP=1', '-q', '-h', (string) ($c['host'] ?? '127.0.0.1'), '-p', (string) ($c['port'] ?? 5432), '-U', (string) $c['username'], '-d', (string) $c['database']]);
        @unlink($dump);
        if (! $result->successful()) {
            $this->error(__('psql başarısız oldu: :output', ['output' => Str::limit(trim($result->errorOutput()), 500)]));

            return false;
        }

        if (preg_match('/^APP_KEY=(.+)$/m', $env, $m)) {
            $this->setAppKey(trim($m[1]));
        } else {
            $this->warn(__('Yedekte APP_KEY yok; şifreli ayarların yeniden girilmesi gerekecek.'));
        }

        DB::transaction(function () use ($self, $passphrase, $items, $targetName) {
            $row = Backup::where('key', $self['key'])->first() ?? new Backup;
            $row->forceFill([...$self, 'status' => 'succeeded', 'local' => true, 'cached_at' => null, 'base_id' => null, 'error' => null,
                'target_id' => $targetName !== null ? (BackupTarget::where('name', $targetName)->value('id') ?? $row->target_id) : $row->target_id]);
            $row->passphrase = $passphrase;
            $row->save();
            foreach ($items as $i) {
                $row->items()->updateOrCreate(['name' => $i['name']], [...$i, 'site_id' => DB::table('sites')->where('domain', $i['name'])->value('id')]);
            }
        });
        $this->info('  '.__('Panel veritabanı yüklendi. Kullanmadan önce panelin kuyruk işçilerini yeniden başlatın (php artisan queue:restart).'));

        return true;
    }

    private function download(AgentClient $agent, string $key, string $member, string $file): void
    {
        $out = fopen($file, 'wb');
        $offset = 0;
        try {
            do {
                $chunk = (array) $agent->call('backup.member.read', ['key' => $key, 'name' => 'panel', 'member' => $member, 'offset' => $offset], 120);
                $data = base64_decode((string) ($chunk['data'] ?? ''));
                fwrite($out, $data);
                $offset = (int) ($chunk['offset'] ?? 0);
            } while ($data !== '' && $offset < (int) ($chunk['size'] ?? 0));
        } finally {
            fclose($out);
        }
    }

    private function read(AgentClient $agent, string $key, string $member): string
    {
        $file = tempnam(sys_get_temp_dir(), 'svk');
        try {
            $this->download($agent, $key, $member, $file);

            return (string) file_get_contents($file);
        } catch (AgentException) {
            return '';
        } finally {
            @unlink($file);
        }
    }

    /**
     * Writes the backup's APP_KEY into this panel's .env.
     */
    private function setAppKey(string $key): void
    {
        $file = base_path('.env');
        $env = is_file($file) ? (string) file_get_contents($file) : '';
        $env = preg_match('/^APP_KEY=.*$/m', $env) ? preg_replace('/^APP_KEY=.*$/m', 'APP_KEY='.$key, $env) : rtrim($env)."\nAPP_KEY={$key}\n";
        if (@file_put_contents($file, $env) === false) {
            $this->warn(__(':file yazılamadı; APP_KEY=:key satırını oraya elle ekleyin.', ['file' => $file, 'key' => $key]));

            return;
        }
        config(['app.key' => $key]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        Model::encryptUsing(app('encrypter'));
        $this->line('  '.__('APP_KEY yedekten alındı.'));
    }
}
