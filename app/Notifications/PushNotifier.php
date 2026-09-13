<?php

namespace App\Notifications;

use App\Models\Channel;

/**
 * الإشعارات: دعوة "فلان بلّش لعبة"، "دورك تسحب" والتطبيق مغلق، "اللعبة تبدأ".
 *
 * ثلاث حالات لا أكثر. الإشعارات **ليست أداة تزامن**: تسليمها غير مضمون
 * التوقيت، وiOS يخنق المتكرر منها. كل التزامن عبر القناة الحيّة.
 */
interface PushNotifier
{
    /**
     * @param  array<int, string>  $tokens
     * @param  array<string, string>  $data
     */
    public function send(array $tokens, string $title, string $body, array $data = []): void;

    /**
     * إشعار لأعضاء قناة — وهو سبب وجود القنوات أصلاً: لا سبام على مستوى
     * التطبيق، فقط من يعنيهم الأمر.
     *
     * @param  array<int, string>  $except
     * @param  array<string, string>  $data
     */
    public function toChannelMembers(Channel $channel, array $except, string $title, string $body, array $data = []): void;
}
