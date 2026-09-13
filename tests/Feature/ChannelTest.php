<?php

use App\Models\Channel;
use App\Models\Game;
use App\Models\User;

it('ينشئ قناة ويجعل المنشئ مالكاً برمز دعوة', function () {
    $owner = makeUser('owner');

    $response = $this->postJson('/api/channels', ['name' => 'عيلة أبو أحمد'], authHeaders($owner))
        ->assertCreated()
        ->assertJsonPath('channel.isOwner', true);

    expect($response->json('channel.inviteCode'))->toHaveLength(6);
});

it('ينضم برمز الدعوة بلا موافقة مسبقة', function () {
    $owner = makeUser('owner');
    $guest = makeUser('guest');

    $code = $this->postJson('/api/channels', ['name' => 'العيلة'], authHeaders($owner))
        ->json('channel.inviteCode');

    $this->postJson('/api/channels/join', ['inviteCode' => $code], authHeaders($guest))
        ->assertOk()
        ->assertJsonPath('channel.memberCount', 2);
});

it('يبطل الرمز القديم عند إعادة التوليد', function () {
    $owner = makeUser('owner');
    $guest = makeUser('guest');

    $channel = $this->postJson('/api/channels', ['name' => 'العيلة'], authHeaders($owner))->json('channel');

    $this->postJson("/api/channels/{$channel['id']}/invite-code", [], authHeaders($owner))->assertOk();

    $this->postJson('/api/channels/join', ['inviteCode' => $channel['inviteCode']], authHeaders($guest))
        ->assertStatus(422);
});

it('يمنع غير المالك من تعديل القناة', function () {
    $owner = makeUser('owner');
    $member = makeUser('member');
    $channel = makeChannelWith($owner, [$member]);

    $this->patchJson("/api/channels/{$channel->id}", ['name' => 'اسم جديد'], authHeaders($member))
        ->assertStatus(403);
});

it('ينقل الملكية لأقدم عضو حين يغادر المالك', function () {
    $owner = makeUser('owner');
    $second = makeUser('second');
    $third = makeUser('third');
    $channel = makeChannelWith($owner, [$second, $third]);

    $this->postJson("/api/channels/{$channel->id}/leave", [], authHeaders($owner))->assertOk();

    expect($channel->fresh()->owner_id)->toBe($second->id);
});

it('يسمح بلعبة نشطة واحدة فقط لكل قناة', function () {
    $owner = makeUser('owner');
    $member = makeUser('member');
    $channel = makeChannelWith($owner, [$member]);

    $this->postJson("/api/channels/{$channel->id}/games", ['gameType' => 'harf'], authHeaders($owner))
        ->assertCreated();

    $this->postJson("/api/channels/{$channel->id}/games", ['gameType' => 'harf'], authHeaders($member))
        ->assertStatus(422)
        ->assertJsonValidationErrors('gameType');

    expect(Game::where('channel_id', $channel->id)->count())->toBe(1);
});

it('يمنع من ليس عضواً من رؤية القناة', function () {
    $owner = makeUser('owner');
    $outsider = makeUser('outsider');
    $channel = makeChannelWith($owner, []);

    $this->getJson("/api/channels/{$channel->id}", authHeaders($outsider))->assertStatus(403);
});

it('يعيد سجل الألعاب المنتهية فقط', function () {
    $owner = makeUser('owner');
    $channel = makeChannelWith($owner, []);

    Game::create([
        'channel_id' => $channel->id, 'game_type' => 'harf', 'status' => Game::STATUS_FINISHED,
        'config' => [], 'started_by' => $owner->id, 'finished_at' => now(),
        'result' => ['winnerId' => $owner->id],
    ]);
    Game::create([
        'channel_id' => $channel->id, 'game_type' => 'harf', 'status' => Game::STATUS_LOBBY,
        'config' => [], 'started_by' => $owner->id,
    ]);

    $this->getJson("/api/channels/{$channel->id}/games", authHeaders($owner))
        ->assertOk()
        ->assertJsonCount(1, 'games');
});

/**
 * @param  array<int, User>  $members
 */
function makeChannelWith(User $owner, array $members): Channel
{
    $channel = Channel::create([
        'name' => 'قناة اختبار',
        'owner_id' => $owner->id,
        'invite_code' => Channel::generateInviteCode(),
    ]);

    $channel->members()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);

    foreach ($members as $index => $member) {
        $channel->members()->attach($member->id, [
            'role' => 'member',
            'joined_at' => now()->addSeconds($index + 1),
        ]);
    }

    return $channel;
}
