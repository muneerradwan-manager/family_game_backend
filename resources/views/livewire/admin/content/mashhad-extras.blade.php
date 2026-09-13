<div>
    <div class="page-head">
        <div>
            <h1>🎭 أدوار وجوائز المشهد</h1>
            <p>أدوار الحشو بتكمّل أدوار المشهد لما يكون اللاعبين أكتر من أدواره الأساسية.</p>
        </div>
        <div class="page-actions">
            <a class="btn" href="{{ route('admin.content.scenes') }}" wire:navigate>🎬 المشاهد</a>
            <a class="btn" href="{{ route('admin.games.settings', 'mashhad') }}" wire:navigate>⚙️ أرقام اللعبة</a>
        </div>
    </div>

    <div class="stack">
        <form class="card" wire:submit="saveRoles">
            @include('livewire.admin.content.partials.section-head', ['title' => 'أدوار الحشو ('.count($fillerRoles).')', 'key' => 'mashhad.filler_roles', 'overridden' => $overridden['mashhad.filler_roles']])
            <div class="card-body">
                <p class="muted small" style="margin-top:0">أهدافها اجتماعية عامة بتصلح لأي مشهد. الحد الأدنى {{ $minFillers }} دور.</p>
                @error('fillerRoles') <div class="error" style="margin-bottom:8px">{{ $message }}</div> @enderror
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th style="width:22%">الدور</th><th>الهدف</th><th style="width:120px">الصعوبة</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($fillerRoles as $index => $role)
                            <tr wire:key="role-{{ $index }}">
                                <td>
                                    <input class="input" wire:model="fillerRoles.{{ $index }}.name" maxlength="40">
                                    @error("fillerRoles.$index.name") <span class="error">{{ $message }}</span> @enderror
                                </td>
                                <td>
                                    <input class="input" wire:model="fillerRoles.{{ $index }}.goal" maxlength="200">
                                    @error("fillerRoles.$index.goal") <span class="error">{{ $message }}</span> @enderror
                                </td>
                                <td>
                                    <select class="select" wire:model="fillerRoles.{{ $index }}.difficulty">
                                        @foreach ($levels as $value => $name)
                                            <option value="{{ $value }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="actions"><button type="button" class="btn btn-sm btn-soft-danger" wire:click="removeRole({{ $index }})">حذف</button></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-foot row">
                <button class="btn btn-primary" type="submit">حفظ الأدوار</button>
                <button class="btn" type="button" wire:click="addRole">+ دور</button>
            </div>
        </form>

        <div class="grid grid-2">
            <form class="card" wire:submit="saveAwards">
                @include('livewire.admin.content.partials.section-head', ['title' => 'جوائز نهاية السهرة', 'key' => 'mashhad.awards', 'overridden' => $overridden['mashhad.awards']])
                <div class="card-body stack">
                    @error('awards') <div class="error">{{ $message }}</div> @enderror
                    @foreach ($awards as $index => $award)
                        <div class="row" wire:key="award-{{ $index }}" style="align-items:flex-start">
                            <input class="input" style="width:64px;text-align:center" wire:model="awards.{{ $index }}.emoji" maxlength="8">
                            <div class="field" style="flex:2">
                                <input class="input" wire:model="awards.{{ $index }}.label" maxlength="40" placeholder="اسم الجائزة">
                                @error("awards.$index.label") <span class="error">{{ $message }}</span> @enderror
                            </div>
                            <div class="field" style="flex:1">
                                <input class="input ltr mono" wire:model="awards.{{ $index }}.key" maxlength="30" placeholder="key">
                                @error("awards.$index.key") <span class="error">{{ $message }}</span> @enderror
                            </div>
                            <button type="button" class="btn btn-sm btn-soft-danger" wire:click="removeAward({{ $index }})">✕</button>
                        </div>
                    @endforeach
                </div>
                <div class="card-foot row">
                    <button class="btn btn-primary" type="submit">حفظ الجوائز</button>
                    <button class="btn" type="button" wire:click="addAward">+ جائزة</button>
                </div>
            </form>

            <form class="card" wire:submit="saveCategories">
                @include('livewire.admin.content.partials.section-head', ['title' => 'أسماء مجموعات المشاهد', 'key' => 'mashhad.categories', 'overridden' => $overridden['mashhad.categories']])
                <div class="card-body stack">
                    @foreach ($categories as $key => $category)
                        <div class="row" wire:key="mcat-{{ $key }}" style="align-items:flex-start">
                            <input class="input" style="width:64px;text-align:center" wire:model="categories.{{ $key }}.emoji" maxlength="8">
                            <div class="field" style="flex:1">
                                <input class="input" wire:model="categories.{{ $key }}.label" maxlength="30">
                                @error("categories.$key.label") <span class="error">{{ $message }}</span> @enderror
                            </div>
                            <span class="muted small mono" style="padding-top:10px">{{ $key }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="card-foot"><button class="btn btn-primary" type="submit">حفظ الأسماء</button></div>
            </form>
        </div>
    </div>
</div>
