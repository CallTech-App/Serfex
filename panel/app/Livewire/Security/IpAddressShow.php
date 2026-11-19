<?php

namespace App\Livewire\Security;

use App\Services\Monitor\IpBlocks;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Güvenlik > IP engelleri > one address: what it did on the sites, and
 * its blocks.
 */
#[Layout('components.layouts.app')]
class IpAddressShow extends Component
{
    use UndoesBlocks;

    public string $address = '';

    public function mount(string $address): void
    {
        abort_if(IpBlocks::flaw($address) !== null, 404);
        $this->address = IpBlocks::normalize($address);
    }

    public function render(): View
    {
        return view('livewire.security.ip-address')->title($this->address.' · Serfex');
    }
}
