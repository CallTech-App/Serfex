<?php

namespace App\Http\Controllers\Databases;

use App\Http\Controllers\Controller;
use App\Models\Database;
use App\Services\Agent\AgentException;
use App\Services\Databases\Databases;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Opens a database in phpMyAdmin or Adminer without a password: the agent
 * makes a temporary user and a one-time sign-in link valid for a minute,
 * served by Servik's own web server on its own port.
 */
class DatabaseManagerController extends Controller
{
    public function __invoke(Request $request, int $database, Databases $databases): RedirectResponse
    {
        $db = Database::visibleTo($request->user())->findOrFail($database);
        if ($db->systemUser?->customer?->isSuspended()) {
            return $this->fail($db, __('Hesap askıda; veritabanı yöneticisi açılamaz.'));
        }

        try {
            return redirect()->away($databases->managerUrl($db, $request->getHost()));
        } catch (AgentException $e) {
            return $this->fail($db, __(':manager_label açılamadı: :reason', ['manager_label' => $db->managerLabel(), 'reason' => $e->reason]));
        }
    }

    private function fail(Database $database, string $message): RedirectResponse
    {
        return redirect()->route('databases.show', $database)->with('toast', $message);
    }
}
