<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @elseif ($current_step === 1)
        <div class="flex flex-col gap-6">
            <x-server.provider-token-picker provider="dataforest" providerLabel="dataforest"
                :tokens="$available_tokens" />
            <p class="text-[11px] text-neutral-500 dark:text-fg-faint">
                New to dataforest?
                <a href="https://cloud.dataforest.net/en" target="_blank" rel="noopener noreferrer"
                    class="font-medium text-coollabs hover:underline dark:text-brand-light">Create an account</a>,
                then add a Personal Access Token from your team settings.
            </p>
        </div>
    @elseif ($current_step === 2)
        <div wire:init="loadDataforestData">
            @if ($loading_data)
                <x-application.settings-section title="Loading dataforest"
                    description="Fetching projects, regions, and account SSH keys.">
                    <x-skeleton.form :fields="6" label="Loading dataforest data" />
                </x-application.settings-section>
            @elseif ($provider_data_error)
                <x-application.settings-section title="Unable to load dataforest"
                    description="The selected token could not access the provider API.">
                    <x-callout type="error" title="Provider request failed">
                        <pre class="mt-2 whitespace-pre-wrap break-words text-[11px]">{{ $provider_data_error }}</pre>
                    </x-callout>
                    <div class="mt-4">
                        <a class="button" href="{{ route('server.create.type', ['type' => 'dataforest']) }}"
                            {{ wireNavigate() }}>Select another token</a>
                    </div>
                </x-application.settings-section>
            @else
                @php
                    $projectOptions = collect($projects)->map(fn ($project) => [
                        'value' => $project['id'],
                        'label' => $project['name'],
                    ])->values()->all();
                    $regionOptions = collect($regions)->map(fn ($region) => [
                        'value' => $region['slug'],
                        'label' => $region['name'] ?? $region['slug'],
                        'description' => collect([$region['city'] ?? null, $region['country'] ?? $region['country_code'] ?? null])
                            ->filter()->join(' · '),
                    ])->values()->all();
                    $planOptions = collect($plans)->map(function ($plan) {
                        $label = $plan['name']
                            . ' · ' . ($plan['cpu'] ?? '?') . ' vCPU'
                            . ' · ' . ($plan['memory'] ?? '?') . ' GB RAM'
                            . ' · ' . ($plan['storage'] ?? '?') . ' GB';
                        $price = $plan['gross_max_price_per_month'] ?? null;
                        return [
                            'value' => $plan['plan'],
                            'label' => filled($price) ? $label . ' · €' . number_format((float) $price, 2) . '/mo' : $label,
                            'description' => $plan['line_name'] ? $plan['line_name'] . ' line' : null,
                        ];
                    })->values()->all();
                    $imageOptions = collect($this->availableImages)->map(fn ($image) => [
                        'value' => $image['ref'],
                        // display_name already carries the image name, e.g. "Debian 13".
                        'label' => $image['display_name'] ?: trim($image['image_name'] . ' ' . $image['version']),
                    ])->values()->all();
                    $privateKeyOptions = $private_keys->map(fn ($key) => [
                        'value' => $key->id,
                        'label' => $key->name,
                    ])->values()->all();
                    $scriptOptions = collect([
                        ['value' => '', 'label' => 'Start with an empty script'],
                        ...$saved_cloud_init_scripts->map(fn ($script) => [
                            'value' => $script->id,
                            'label' => $script->name,
                        ])->all(),
                    ])->all();
                @endphp

                <form wire:submit="submit" class="flex flex-col gap-6">
                    <x-application.settings-section title="dataforest seed"
                        description="Choose the project, region, plan, operating system, and Coolify SSH key.">
                        <x-slot:actions>
                            <button type="submit" class="button button-highlighted"
                                wire:loading.attr="disabled" wire:target="submit"
                                @disabled(!$private_key_id)>
                                Buy and create
                                @if ($this->selectedPlanPrice)
                                    <span class="opacity-70">· {{ $this->selectedPlanPrice }}/mo</span>
                                @endif
                                <x-loading-on-button wire:loading wire:target="submit" />
                            </button>
                        </x-slot:actions>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div class="lg:col-span-2">
                                <x-forms.input id="server_name" label="Server name"
                                    helper="A friendly name shown in Coolify and used as the seed name. 3 to 50 characters." />
                            </div>
                            <x-forms.listbox id="selected_project_id" label="Project" required
                                placeholder="Select a project" :options="$projectOptions"
                                helper="Every dataforest seed belongs to one project of your team." />
                            <x-forms.listbox id="selected_region" label="Region" required live
                                placeholder="Select a region" :options="$regionOptions" />
                            <x-forms.listbox id="selected_plan" label="Plan" required live
                                :disabled="!$selected_region || $loading_region" placeholder="{{ $loading_region ? 'Loading plans…' : 'Select a plan' }}"
                                :options="$planOptions" searchable searchPlaceholder="Search plans…" />
                            <x-forms.listbox id="selected_image_ref" label="Image" required
                                :disabled="!$selected_plan" placeholder="Select an image"
                                :options="$imageOptions" searchable searchPlaceholder="Search images…" />
                            @if ($private_keys->isEmpty())
                                <div>
                                    <label class="mb-1.5 flex w-fit items-center gap-1.5">Private key
                                        <x-highlighted text="*" />
                                    </label>
                                    <div
                                        class="flex min-h-8 items-center justify-between gap-3 rounded-lg border border-warning/30 bg-warning/5 px-3 py-2">
                                        <span class="text-[11px] text-neutral-600 dark:text-fg-dim">A private key is required.</span>
                                        <x-modal-input title="New Private Key">
                                            <x-slot:content>
                                                <button type="button" class="button">Create key</button>
                                            </x-slot:content>
                                            <livewire:security.private-key.create :modal_mode="true" from="server" />
                                        </x-modal-input>
                                    </div>
                                </div>
                            @else
                                <x-forms.listbox id="private_key_id" label="Private key" required
                                    placeholder="Select a private key" :options="$privateKeyOptions"
                                    helper="Coolify adds this key to your dataforest key ring and to the new seed." />
                            @endif
                        </div>

                        @if ($selected_region && !$loading_region && count($plans) === 0)
                            <x-callout type="warning" title="No plans available" class="mt-4">
                                This region currently offers no plan your team can order. Pick another region.
                            </x-callout>
                        @endif
                    </x-application.settings-section>

                    <x-application.settings-section title="Advanced options"
                        description="Public IPv4, provider backups, account SSH keys, and cloud-init.">
                        @if (count($this->advancedDataforestOptionsSummary) > 0)
                            <div class="mb-4 flex flex-wrap gap-1.5">
                                @foreach ($this->advancedDataforestOptionsSummary as $summaryItem)
                                    <span
                                        class="rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-medium text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                                        {{ $summaryItem }}
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        <div class="flex flex-col gap-4">
                            <div class="-mx-2.5 grid gap-3 lg:grid-cols-2">
                                <x-forms.checkbox id="enable_ipv4" label="Add a public IPv4 address" fullWidth
                                    helper="{{ $this->ipv4Surcharge ? 'Costs up to ' . $this->ipv4Surcharge . '/mo. ' : '' }}Without it the seed is reachable over IPv6 only, so Coolify needs IPv6 connectivity to manage it." />
                                <x-forms.checkbox id="enable_backups" label="Enable daily dataforest backups" fullWidth
                                    helper="{{ $this->backupSurcharge ? 'Costs up to ' . $this->backupSurcharge . '/mo.' : 'Billed by dataforest on top of the plan.' }}" />
                            </div>

                            @if ($enable_ipv4 && $region_ipv4_status === 'insufficient')
                                <x-callout type="warning" title="No free IPv4 address in this region">
                                    dataforest has no public IPv4 address available here right now. Pick another
                                    region, or uncheck the IPv4 option to create an IPv6-only seed.
                                </x-callout>
                            @endif

                            <x-forms.listbox label="Extra SSH keys" id="selectedDataforestSshKeyIds"
                                helper="Existing keys from your dataforest key ring. The Coolify key above is always added."
                                multiple searchable :disabled="count($dataforestSshKeys) === 0"
                                searchPlaceholder="Search SSH keys…"
                                :placeholder="count($dataforestSshKeys) ? 'Select SSH keys' : 'No account keys found'"
                                :options="collect($dataforestSshKeys)->map(fn ($sshKey) => ['value' => (string) $sshKey['id'], 'label' => $sshKey['name']])->values()->all()" />

                            <div class="border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                                @if (!$show_cloud_init_script && blank($cloud_init_script) && blank($selected_cloud_init_script_id))
                                    <button type="button" class="button" wire:click="showCloudInitScript">
                                        <x-reicon name="plus" class="size-3.5" />
                                        Add cloud-init script
                                    </button>
                                @else
                                    <div class="flex flex-col gap-4">
                                        <div class="grid items-end gap-3 lg:grid-cols-[minmax(0,1fr)_auto]">
                                            <x-forms.listbox id="selected_cloud_init_script_id"
                                                label="Saved cloud-init script" live :options="$scriptOptions" />
                                            <button type="button" class="button"
                                                wire:click="clearCloudInitScript">Clear</button>
                                        </div>
                                        <x-forms.textarea id="cloud_init_script" label="Cloud-init script" rows="8"
                                            monospace
                                            helper="A #cloud-config document is merged with the managed cloud-init; anything else runs as a script on first boot. Your SSH keys are always kept." />
                                        <div class="grid items-end gap-4 lg:grid-cols-2">
                                            <x-forms.checkbox id="save_cloud_init_script"
                                                label="Save this script for later" />
                                            @if ($save_cloud_init_script)
                                                <x-forms.input id="cloud_init_script_name" label="Saved script name" />
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            </div>

                            <x-callout type="warning" title="This purchase is billed by dataforest">
                                The seed is created on your dataforest team and billed to its payment method. Review
                                the plan and region before continuing.
                            </x-callout>
                        </div>
                    </x-application.settings-section>
                </form>
            @endif
        </div>
    @endif
</div>
