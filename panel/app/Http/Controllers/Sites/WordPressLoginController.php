<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Audit\AuditLog;
use App\Services\Sites\WordPressSites;
use Illuminate\Http\RedirectResponse;

/**
 * "WordPress'e giriş yap": signs the person in to the site's WordPress
 * dashboard as its first administrator with a one-time link, in a new
 * tab. A form posts here so the link is made only on purpose.
 */
class WordPressLoginController extends Controller
{
    public function __invoke(int $site, WordPressSites $wordpress): RedirectResponse
    {
        $user = auth()->user();
        $model = Site::visibleTo($user)->with('systemUser', 'wordpress')->findOrFail($site);
        $install = $model->wordpress;
        abort_if($install === null || ! $install->isActive(), 404);
        abort_if($user->isReadOnly(), 403);
        $install->setRelation('site', $model);
        try {
            $url = $wordpress->loginUrl($install);
        } catch (AgentException $e) {
            return redirect()->route('sites.wordpress', $model)
                ->with('toast', __('WordPress\'e giriş bağlantısı oluşturulamadı: :reason', ['reason' => $e->reason ?: $e->getMessage()]));
        }
        AuditLog::record(__(':domain WordPress yönetimine giriş yapıldı.', ['domain' => $model->domain]), $user, 'panel', [
            'section' => __('Siteler').' · '.$model->domain,
            'url' => route('sites.wordpress', $model, false),
        ]);

        return redirect()->away($url);
    }
}
