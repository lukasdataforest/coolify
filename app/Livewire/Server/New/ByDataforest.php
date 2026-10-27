<?php

namespace App\Livewire\Server\New;

use App\Enums\ProxyTypes;
use App\Models\CloudInitScript;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Rules\ValidCloudInitYaml;
use App\Rules\ValidHostname;
use App\Services\DataforestService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ByDataforest extends Component
{
    use AuthorizesRequests;

    /**
     * dataforest caps a seed name at 50 characters.
     */
    private const MAX_SEED_NAME_LENGTH = 50;

    public int $current_step = 1;

    #[Locked]
    public Collection $available_tokens;

    #[Locked]
    public $private_keys;

    #[Locked]
    public $limit_reached;

    #[Locked]
    public Collection $saved_cloud_init_scripts;

    // Step 1: token selection
    public ?int $selected_token_id = null;

    public ?string $selectedTokenUuid = null;

    // Step 2: provider catalog
    public array $projects = [];

    public array $regions = [];

    public array $plans = [];

    public array $images = [];

    public array $dataforestSshKeys = [];

    public ?string $selected_project_id = null;

    public ?string $selected_region = null;

    public ?string $selected_plan = null;

    public ?string $selected_image_ref = null;

    public array $selectedDataforestSshKeyIds = [];

    public string $server_name = '';

    public ?int $private_key_id = null;

    public bool $enable_ipv4 = true;

    public bool $enable_backups = false;

    public bool $loading_data = false;

    public bool $loading_region = false;

    public ?string $provider_data_error = null;

    public ?string $region_ipv4_status = null;

    public bool $show_cloud_init_script = false;

    public ?string $cloud_init_script = null;

    public bool $save_cloud_init_script = false;

    public ?string $cloud_init_script_name = null;

    public ?int $selected_cloud_init_script_id = null;

    public bool $from_onboarding = false;

    public function mount(?string $selectedTokenUuid = null): mixed
    {
        try {
            $this->authorize('viewAny', CloudProviderToken::class);
            $this->loadTokens();
            $this->selectTokenFromUrl($selectedTokenUuid);
            $this->loadSavedCloudInitScripts();
            $this->server_name = Str::limit(generate_random_name(), self::MAX_SEED_NAME_LENGTH, '');
            $this->private_keys = PrivateKey::ownedAndOnlySShKeys()->where('id', '!=', 0)->get();

            if ($this->private_keys->isNotEmpty()) {
                $this->private_key_id = $this->private_keys->first()->id;
            }

            if ($this->selectedTokenUuid) {
                $this->current_step = 2;
                $this->loading_data = true;
            }

            return null;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function getListeners(): array
    {
        return [
            'tokenAdded.dataforest' => 'handleTokenAdded',
            'privateKeyCreated' => 'handlePrivateKeyCreated',
            'modalClosed' => 'resetSelection',
        ];
    }

    public function loadTokens(): void
    {
        $this->available_tokens = CloudProviderToken::ownedByCurrentTeam()
            ->where('provider', 'dataforest')
            ->get();
    }

    public function loadSavedCloudInitScripts(): void
    {
        $this->saved_cloud_init_scripts = CloudInitScript::ownedByCurrentTeam()->get();
    }

    public function handleTokenAdded(int $tokenId): mixed
    {
        $this->loadTokens();
        $this->selected_token_id = $tokenId;

        return $this->nextStep();
    }

    public function handlePrivateKeyCreated(int $keyId): void
    {
        $this->private_keys = PrivateKey::ownedAndOnlySShKeys()->where('id', '!=', 0)->get();
        $this->private_key_id = $keyId;
        $this->resetErrorBag('private_key_id');
    }

    public function resetSelection(): void
    {
        $this->selected_token_id = null;
        $this->current_step = 1;
        $this->enable_backups = false;
        $this->selectedDataforestSshKeyIds = [];
        $this->clearCloudInitScript();
    }

    protected function rules(): array
    {
        $rules = [
            'selected_token_id' => 'required|integer|exists:cloud_provider_tokens,id',
        ];

        if ($this->current_step === 2) {
            $rules = array_merge($rules, [
                'server_name' => ['required', 'string', 'min:3', 'max:'.self::MAX_SEED_NAME_LENGTH, new ValidHostname],
                'selected_project_id' => 'required|string',
                'selected_region' => 'required|string',
                'selected_plan' => 'required|string',
                'selected_image_ref' => 'required|string',
                'private_key_id' => 'required|integer|exists:private_keys,id,team_id,'.currentTeam()->id,
                'selectedDataforestSshKeyIds' => 'nullable|array',
                'selectedDataforestSshKeyIds.*' => 'string',
                'enable_ipv4' => 'required|boolean',
                'enable_backups' => 'required|boolean',
                'show_cloud_init_script' => 'boolean',
                'cloud_init_script' => ['nullable', 'string', new ValidCloudInitYaml],
                'save_cloud_init_script' => 'boolean',
                'cloud_init_script_name' => 'nullable|string|max:255',
                'selected_cloud_init_script_id' => 'nullable|integer|exists:cloud_init_scripts,id',
            ]);
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'selected_token_id.required' => 'Please select a dataforest token.',
            'selected_token_id.exists' => 'Selected token not found.',
            'selected_project_id.required' => 'Please select the dataforest project the seed belongs to.',
            'server_name.min' => 'dataforest needs a seed name of at least 3 characters.',
            'server_name.max' => 'dataforest allows at most '.self::MAX_SEED_NAME_LENGTH.' characters in a seed name.',
        ];
    }

    public function nextStep(): mixed
    {
        $this->validate([
            'selected_token_id' => 'required|integer|exists:cloud_provider_tokens,id',
        ]);

        try {
            if (! $this->selectedTokenUuid) {
                $token = $this->available_tokens->firstWhere('id', $this->selected_token_id);

                if ($token) {
                    return $this->redirectRoute('server.create.token', [
                        'type' => 'dataforest',
                        'token_uuid' => $token->uuid,
                    ], navigate: true);
                }
            }

            $this->current_step = 2;
            $this->loading_data = true;

            return null;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function previousStep(): mixed
    {
        if ($this->selectedTokenUuid) {
            return $this->redirectRoute('server.create.type', ['type' => 'dataforest'], navigate: true);
        }

        $this->current_step = 1;

        return null;
    }

    /**
     * Load everything that does not depend on the chosen region: projects, regions, and the
     * team's SSH key ring. Plans and images follow the region, see loadRegionCatalog().
     */
    public function loadDataforestData(): void
    {
        $token = $this->getDataforestToken();

        if (! $token) {
            $this->loading_data = false;
            $this->dispatch('error', 'Please select a valid dataforest token.');

            return;
        }

        $this->loading_data = true;
        $this->provider_data_error = null;

        try {
            $dataforestService = new DataforestService($token);

            $team = $dataforestService->getTeam();
            $this->projects = collect($team['projects'] ?? [])
                ->map(fn (array $project): array => [
                    'id' => (string) $project['id'],
                    'name' => $project['name'] ?? $project['id'],
                ])
                ->values()
                ->all();

            if (count($this->projects) === 1) {
                $this->selected_project_id = $this->projects[0]['id'];
            }

            $this->regions = $dataforestService->getRegions();
            $this->dataforestSshKeys = collect($dataforestService->getSshKeys())
                ->sortBy('name')
                ->values()
                ->all();
        } catch (\Throwable $e) {
            $this->provider_data_error = $e->getMessage();
            $this->dispatch('error', $this->provider_data_error);
        } finally {
            $this->loading_data = false;
        }
    }

    public function updatedSelectedRegion(): void
    {
        $this->selected_plan = null;
        $this->selected_image_ref = null;
        $this->plans = [];
        $this->images = [];
        $this->region_ipv4_status = null;

        $this->loadRegionCatalog();
    }

    public function updatedSelectedPlan(): void
    {
        // A GPU image only boots on a GPU plan, so the image list depends on the plan.
        if ($this->selected_image_ref !== null && ! collect($this->availableImages)->contains('ref', $this->selected_image_ref)) {
            $this->selected_image_ref = null;
        }
    }

    /**
     * Plans and images are per region, and so is whether a public IPv4 can be ordered today.
     */
    private function loadRegionCatalog(): void
    {
        if (! $this->selected_region) {
            return;
        }

        $token = $this->getDataforestToken();

        if (! $token) {
            return;
        }

        $this->loading_region = true;

        try {
            $dataforestService = new DataforestService($token);
            $region = $dataforestService->getRegion($this->selected_region, checkAvailability: true);

            $this->plans = $dataforestService->getRegionPlans($region);
            $this->images = $dataforestService->getRegionImageVersions($region, includeGpuImages: true);
            $this->region_ipv4_status = data_get($region, 'ipv4.capacity_status');
        } catch (\Throwable $e) {
            $this->plans = [];
            $this->images = [];
            $this->dispatch('error', $e->getMessage());
        } finally {
            $this->loading_region = false;
        }
    }

    public function getSelectedPlanDetailsProperty(): ?array
    {
        return collect($this->plans)->firstWhere('plan', $this->selected_plan);
    }

    /**
     * GPU images need a GPU plan; everything else runs on any plan.
     */
    public function getAvailableImagesProperty(): array
    {
        $planHasGpu = filled(data_get($this->selectedPlanDetails, 'gpu'));

        return collect($this->images)
            ->filter(fn (array $image): bool => $planHasGpu || ($image['hardware'] ?? 'generic') !== 'gpu')
            ->values()
            ->all();
    }

    public function getSelectedPlanPriceProperty(): ?string
    {
        $price = data_get($this->selectedPlanDetails, 'gross_max_price_per_month');

        return filled($price) ? '€'.number_format((float) $price, 2) : null;
    }

    public function getIpv4SurchargeProperty(): ?string
    {
        $price = data_get($this->selectedPlanDetails, 'gross_max_ipv4_price_per_month');

        return filled($price) ? '€'.number_format((float) $price, 2) : null;
    }

    public function getBackupSurchargeProperty(): ?string
    {
        $price = data_get($this->selectedPlanDetails, 'gross_max_backup_price_per_month');

        return filled($price) ? '€'.number_format((float) $price, 2) : null;
    }

    public function getAdvancedDataforestOptionsSummaryProperty(): array
    {
        $summary = [];

        if (count($this->selectedDataforestSshKeyIds) > 0) {
            $summary[] = count($this->selectedDataforestSshKeyIds).' extra SSH '.str('key')->plural(count($this->selectedDataforestSshKeyIds));
        }

        if ($this->enable_backups) {
            $summary[] = 'Backups on';
        }

        if (! $this->enable_ipv4) {
            $summary[] = 'IPv6 only';
        }

        if ($this->show_cloud_init_script || filled($this->cloud_init_script) || filled($this->selected_cloud_init_script_id)) {
            $summary[] = 'Cloud-init';
        }

        return $summary;
    }

    public function showCloudInitScript(): void
    {
        $this->show_cloud_init_script = true;
    }

    public function updatedSelectedCloudInitScriptId($value): void
    {
        if ($value) {
            $script = CloudInitScript::ownedByCurrentTeam()->findOrFail($value);
            $this->cloud_init_script = $script->script;
            $this->cloud_init_script_name = $script->name;
            $this->show_cloud_init_script = true;
        }
    }

    public function updatedSaveCloudInitScript(bool $value): void
    {
        if (! $value) {
            $this->cloud_init_script_name = null;
        }
    }

    public function clearCloudInitScript(): void
    {
        $this->selected_cloud_init_script_id = null;
        $this->cloud_init_script = '';
        $this->cloud_init_script_name = '';
        $this->save_cloud_init_script = false;
        $this->show_cloud_init_script = false;
    }

    public function submit(): mixed
    {
        $this->validate();

        $seedId = null;

        try {
            $this->authorize('create', Server::class);

            if (Team::serverLimitReached()) {
                return $this->dispatch('error', 'You have reached the server limit for your subscription.');
            }

            if (! $this->providerSelectionsAreValid()) {
                return null;
            }

            if ($this->save_cloud_init_script && filled($this->cloud_init_script) && filled($this->cloud_init_script_name)) {
                $this->authorize('create', CloudInitScript::class);

                $cloudInitScript = CloudInitScript::create([
                    'team_id' => currentTeam()->id,
                    'name' => $this->cloud_init_script_name,
                    'script' => $this->cloud_init_script,
                ]);

                auditLog('ui.cloud_init_script.created', [
                    'team_id' => currentTeam()->id,
                    'cloud_init_script_id' => $cloudInitScript->id,
                    'cloud_init_script_name' => $cloudInitScript->name,
                ]);
            }

            $privateKey = PrivateKey::ownedByCurrentTeam()->findOrFail($this->private_key_id);
            $dataforestService = new DataforestService($this->getDataforestToken());

            $normalizedServerName = strtolower(trim($this->server_name));

            // The Coolify key must be on the seed, or Coolify cannot reach it over SSH.
            $sshKeyIds = array_values(array_unique(array_merge(
                [$dataforestService->ensureSshKey($privateKey->name, $privateKey->getPublicKey())],
                array_map('strval', $this->selectedDataforestSshKeyIds),
            )));

            $params = [
                'name' => $normalizedServerName,
                'project_id' => $this->selected_project_id,
                'location' => $this->selected_region,
                'plan' => $this->selected_plan,
                'source' => [
                    'type' => 'image',
                    'ref' => $this->selected_image_ref,
                ],
                'ssh_keys' => $sshKeyIds,
                'enable_ipv4' => $this->enable_ipv4,
                'backups' => $this->enable_backups,
            ];

            if (filled($this->cloud_init_script)) {
                $params['user_data'] = $this->cloud_init_script;
            }

            // A timeout on our side must not order a second seed.
            $seed = $dataforestService->createSeed($params, idempotencyKey: (string) Str::uuid());
            $seedId = (string) $seed['id'];

            // Record the seed before waiting for its address, so a seed is never orphaned at the
            // provider. The cloud provider status check backfills the placeholder IP later.
            $server = Team::createServerWithinLimit(currentTeam()->id, [
                'name' => $normalizedServerName,
                'ip' => Server::PLACEHOLDER_IP,
                'user' => 'root',
                'port' => 22,
                'team_id' => currentTeam()->id,
                'private_key_id' => $privateKey->id,
                'cloud_provider_token_id' => $this->selected_token_id,
                'dataforest_seed_id' => $seedId,
                'dataforest_seed_status' => $seed['state'] ?? null,
            ]);

            $server->proxy->set('status', 'exited');
            $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
            $server->save();

            try {
                $seed = $dataforestService->waitForPublicIp($seed);
                $ipAddress = $dataforestService->getPublicIpAddress($seed);
                if ($ipAddress) {
                    $server->update([
                        'ip' => $ipAddress,
                        'dataforest_seed_status' => $seed['state'] ?? $server->dataforest_seed_status,
                    ]);
                }
            } catch (\Throwable $e) {
                report($e);
            }

            if ($this->from_onboarding) {
                currentTeam()->update(['show_boarding' => false]);
                refreshSession();
            }

            return redirectRoute($this, 'server.show', [$server->uuid]);
        } catch (ValidationException $e) {
            if (! isset($e->errors()['server'])) {
                throw $e;
            }

            if (isset($dataforestService) && $seedId) {
                try {
                    $dataforestService->deleteSeed($seedId);
                } catch (\Throwable $cleanupError) {
                    report($cleanupError);
                }
            }

            return $this->dispatch('error', 'You have reached the server limit for your subscription.');
        } catch (\Throwable $e) {
            if ($seedId) {
                logger()->warning('dataforest seed was created but could not be saved in Coolify', [
                    'team_id' => currentTeam()->id,
                    'dataforest_seed_id' => $seedId,
                ]);
                $this->dispatch('error', "dataforest seed {$seedId} was created but could not be saved in Coolify. Manage it in the dataforest Cloud UI.");
            }

            return handleError($e, $this);
        }
    }

    /**
     * Guard against a catalog that moved on while the form was open.
     */
    private function providerSelectionsAreValid(): bool
    {
        if (! collect($this->projects)->contains('id', $this->selected_project_id)) {
            $this->addError('selected_project_id', 'The selected dataforest project is no longer available.');
        }

        if (! collect($this->regions)->contains('slug', $this->selected_region)) {
            $this->addError('selected_region', 'The selected dataforest region is no longer available.');
        }

        if (! collect($this->plans)->contains('plan', $this->selected_plan)) {
            $this->addError('selected_plan', 'The selected dataforest plan is no longer available in this region.');
        }

        if (! collect($this->availableImages)->contains('ref', $this->selected_image_ref)) {
            $this->addError('selected_image_ref', 'The selected dataforest image is no longer available for this plan.');
        }

        $sshKeyIds = collect($this->dataforestSshKeys)->pluck('id')->map(fn ($id) => (string) $id);
        if (collect($this->selectedDataforestSshKeyIds)->contains(fn ($id) => ! $sshKeyIds->contains((string) $id))) {
            $this->addError('selectedDataforestSshKeyIds', 'One or more selected dataforest SSH keys are no longer available.');
        }

        if ($this->enable_ipv4 && $this->region_ipv4_status === 'insufficient') {
            $this->addError('enable_ipv4', 'This region has no free public IPv4 address right now. Pick another region or create an IPv6-only seed.');
        }

        return ! $this->getErrorBag()->isNotEmpty();
    }

    private function selectTokenFromUrl(?string $selectedTokenUuid): void
    {
        if (! $selectedTokenUuid) {
            return;
        }

        $token = $this->available_tokens->firstWhere('uuid', $selectedTokenUuid);

        if ($token) {
            $this->selectedTokenUuid = $selectedTokenUuid;
            $this->selected_token_id = $token->id;
        }
    }

    private function getDataforestToken(): string
    {
        $token = $this->available_tokens->firstWhere('id', $this->selected_token_id);

        return $token?->token ?? '';
    }

    public function render()
    {
        return view('livewire.server.new.by-dataforest');
    }
}
