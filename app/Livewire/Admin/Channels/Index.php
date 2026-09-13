<?php

namespace App\Livewire\Admin\Channels;

use App\Admin\AdminAudit;
use App\Games\GameTerminator;
use App\Livewire\Admin\AdminComponent;
use App\Models\Channel;
use App\Models\Game;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

#[Layout('admin.layout')]
#[Title('القنوات')]
class Index extends AdminComponent
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    /** القناة المفتوحة في نافذة التفاصيل. */
    public ?string $viewingId = null;

    public string $name = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function view(string $id): void
    {
        $channel = Channel::findOrFail($id);

        $this->viewingId = $channel->id;
        $this->name = $channel->name;
        $this->resetValidation();
    }

    public function rename(): void
    {
        $channel = Channel::findOrFail($this->viewingId);

        $this->validate(['name' => ['required', 'string', 'max:60']], [], ['name' => 'اسم القناة']);

        $before = $channel->name;
        $channel->forceFill(['name' => trim($this->name)])->save();

        AdminAudit::record('channel.renamed', "غيّر اسم القناة «{$before}» إلى «{$channel->name}»", $channel, [
            'name' => ['from' => $before, 'to' => $channel->name],
        ]);

        $this->notify('انحفظ اسم القناة.');
    }

    public function regenerateInvite(string $id): void
    {
        $channel = Channel::findOrFail($id);
        $before = $channel->invite_code;

        $channel->forceFill(['invite_code' => Channel::generateInviteCode()])->save();

        AdminAudit::record('channel.invite_regenerated', "جدّد رمز دعوة «{$channel->name}»", $channel, [
            'invite_code' => ['from' => $before, 'to' => $channel->invite_code],
        ]);

        $this->notify("رمز الدعوة الجديد: {$channel->invite_code}");
    }

    public function removeMember(string $userId): void
    {
        $channel = Channel::findOrFail($this->viewingId);

        if ($channel->owner_id === $userId) {
            $this->notify('ما بتقدر تشيل صاحب القناة.', 'error');

            return;
        }

        $member = $channel->members()->where('users.id', $userId)->first();

        if ($member === null) {
            return;
        }

        $channel->members()->detach($userId);

        AdminAudit::record('channel.member_removed', "شال @{$member->username} من «{$channel->name}»", $channel, [
            'userId' => $userId,
        ]);

        $this->notify("انشال @{$member->username} من القناة.");
    }

    public function endActiveGame(string $id, GameTerminator $terminator): void
    {
        $channel = Channel::findOrFail($id);
        $game = $channel->active_game_id ? Game::find($channel->active_game_id) : null;

        if ($game === null || ! $terminator->terminate($game)) {
            // قفل عالق يشير للعبة منتهية أو محذوفة: نحرّره فقط.
            $channel->forceFill(['active_game_id' => null])->save();
            $this->notify('ما كان في لعبة شغّالة — انحرّر قفل القناة.', 'warning');

            return;
        }

        AdminAudit::record('game.terminated', "أنهى جلسة {$game->game_type} في «{$channel->name}»", $game);

        $this->notify('انتهت الجلسة.');
    }

    public function delete(string $id, GameTerminator $terminator): void
    {
        $channel = Channel::findOrFail($id);

        // الجلسات الحيّة تُنهى قبل الحذف: حذف صفوفها بالتتالي وحده يترك
        // حالتها في الذاكرة ومؤقّتاتها في الطابور بلا صاحب.
        Game::query()
            ->where('channel_id', $channel->id)
            ->whereIn('status', [Game::STATUS_LOBBY, Game::STATUS_PLAYING])
            ->get()
            ->each(fn (Game $game) => $terminator->terminate($game, 'حُذفت القناة من الإدارة.'));

        AdminAudit::record('channel.deleted', "حذف القناة «{$channel->name}»", $channel, [
            'owner_id' => $channel->owner_id,
            'invite_code' => $channel->invite_code,
        ]);

        $channel->delete();

        if ($this->viewingId === $id) {
            $this->viewingId = null;
        }

        $this->notify('انحذفت القناة.');
    }

    public function render()
    {
        $term = trim($this->search);

        $channels = Channel::query()
            ->with(['owner:id,username,full_name', 'activeGame:id,game_type,status'])
            ->withCount(['members', 'games'])
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('invite_code', strtoupper($term))))
            ->latest()
            ->paginate(20);

        $viewing = $this->viewingId
            ? Channel::with(['owner', 'members'])->withCount('games')->find($this->viewingId)
            : null;

        return view('livewire.admin.channels.index', [
            'channels' => $channels,
            'viewing' => $viewing,
            'recentGames' => $viewing
                ? Game::where('channel_id', $viewing->id)->latest()->limit(10)->get()
                : collect(),
        ]);
    }
}
