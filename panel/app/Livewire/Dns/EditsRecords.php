<?php

namespace App\Livewire\Dns;

use App\Support\DnsRecords;

/**
 * The "Kayıt ekle / düzenle" dialog, shared by a zone's records and the
 * server-wide template. The component supplies the zone name used to make
 * host names absolute.
 */
trait EditsRecords
{
    public bool $editingRecord = false;

    /** Key of the record being edited; null when adding. */
    public int|string|null $recordKey = null;

    public string $recordType = 'A';

    public string $recordName = '';

    public string $recordValue = '';

    public string $recordPriority = '10';

    public int $recordTtl = 3600;

    /**
     * Open the dialog, empty or filled with an existing record.
     *
     * @param  array{name: string, type: string, content: string, priority: ?int, ttl: int}|null  $record
     */
    protected function openRecordForm(int|string|null $key = null, ?array $record = null, int $defaultTtl = 3600): void
    {
        $this->resetValidation();
        $this->recordKey = $key;
        $this->recordType = $record['type'] ?? 'A';
        $this->recordName = ($record['name'] ?? '@') === '@' ? '' : $record['name'];
        $this->recordValue = $record['content'] ?? '';
        $this->recordPriority = (string) ($record['priority'] ?? 10);
        $this->recordTtl = $record['ttl'] ?? $defaultTtl;
        $this->editingRecord = true;
    }

    /**
     * Validate the dialog against $zone. Returns the record to store, or
     * null after adding errors.
     *
     * @return array{name: string, type: string, content: string, priority: ?int, ttl: int}|null
     */
    protected function validatedRecord(string $zone): ?array
    {
        $this->resetValidation();
        $ok = true;

        if (! in_array($this->recordType, DnsRecords::TYPES, true)) {
            $this->addError('recordType', __('Desteklenmeyen kayıt türü.'));
            $ok = false;
        }
        $name = DnsRecords::normalizeName($this->recordName, $zone);
        if ($name === null) {
            $this->addError('recordName', __('Ana makine yalnızca harf, rakam, - ve _ içerebilir; örneğin www veya _dmarc.'));
            $ok = false;
        }
        [$content, $error] = $ok ? DnsRecords::normalizeContent($this->recordType, $this->recordValue, $zone) : [null, null];
        if ($error !== null) {
            $this->addError('recordValue', $error);
            $ok = false;
        }
        $withPriority = in_array($this->recordType, DnsRecords::WITH_PRIORITY, true);
        if ($withPriority && ! DnsRecords::isValidPriority($this->recordPriority)) {
            $this->addError('recordPriority', __('0 ile 65535 arasında olmalı.'));
            $ok = false;
        }
        if ($this->recordTtl < DnsRecords::MIN_TTL || $this->recordTtl > DnsRecords::MAX_TTL) {
            $this->addError('recordTtl', __('TTL :min_ttl ile :max_ttl saniye arasında olmalı.', ['min_ttl' => DnsRecords::MIN_TTL, 'max_ttl' => DnsRecords::MAX_TTL]));
            $ok = false;
        }
        if (! $ok) {
            return null;
        }

        return [
            'name' => $name,
            'type' => $this->recordType,
            'content' => $content,
            'priority' => $withPriority ? (int) $this->recordPriority : null,
            'ttl' => $this->recordTtl,
        ];
    }

    /**
     * Check a new or changed record against the others of the zone: no
     * duplicates, and a CNAME stands alone and never at the apex.
     *
     * @param  array{name: string, type: string, content: string, priority: ?int, ttl: int}  $record
     * @param  iterable<array{name: string, type: string, content: string, priority: ?int, ttl: int}>  $others  every other record
     */
    protected function conflictsWith(array $record, iterable $others): bool
    {
        if ($record['type'] === 'CNAME' && $record['name'] === '@') {
            $this->addError('recordName', __('Alan adının kendisine CNAME eklenemez; A veya AAAA kaydı kullanın.'));

            return true;
        }
        foreach ($others as $o) {
            if ($o['name'] !== $record['name']) {
                continue;
            }
            if ($o['type'] === $record['type'] && $o['content'] === $record['content'] && $o['priority'] === $record['priority']) {
                $this->addError('recordValue', __('Bu kayıt zaten var.'));

                return true;
            }
            if ($record['type'] === 'CNAME' || $o['type'] === 'CNAME') {
                $this->addError('recordName', $record['type'] === 'CNAME'
                    ? __('Bu ana makinede başka kayıtlar var; CNAME tek başına olmalı.')
                    : __('Bu ana makinede CNAME kaydı var; yanına başka kayıt eklenemez.'));

                return true;
            }
        }

        return false;
    }
}
