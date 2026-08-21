import { Form, Head, router } from '@inertiajs/react';
import { MoreVertical, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import AiProviderCredentialModelsController from '@/actions/App/Http/Controllers/AiProviders/AiProviderCredentialModelsController';
import AiProviderCredentialsController from '@/actions/App/Http/Controllers/AiProviders/AiProviderCredentialsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { home } from '@/routes/dashboard';
import { index, reorder } from '@/routes/dashboard/ai-providers';

type DiscoveredModel = {
    id: string;
    label: string;
};

type Credential = {
    id: number;
    label: string;
    provider: string;
    base_url: string | null;
    discovered_models: DiscoveredModel[] | null;
    priority: number;
    enabled: boolean;
    verified_at: string | null;
};

type ModelEntry = {
    id: number;
    model: string;
    purpose: 'default' | 'vision';
    priority: number;
    credential: {
        id: number;
        label: string;
        provider: string;
    };
};

type PageProps = {
    credentials: Credential[];
    models: {
        default: ModelEntry[];
        vision: ModelEntry[];
    };
};

function providerLabel(provider: string): string {
    return provider === 'anthropic' ? 'Anthropic-style' : 'OpenAI-style';
}

const PROVIDER_DEFAULT_BASE_URL: Record<string, string> = {
    anthropic: 'https://api.anthropic.com',
    openai: 'https://api.openai.com/v1',
};

function ModelChainSection({
    description,
    purpose,
    entries,
    credentials,
}: {
    description: string;
    purpose: 'default' | 'vision';
    entries: ModelEntry[];
    credentials: Credential[];
}) {
    function move(id: number, direction: 'up' | 'down') {
        const ids = entries.map((entry) => entry.id);
        const position = ids.indexOf(id);
        const swapWith = direction === 'up' ? position - 1 : position + 1;

        if (swapWith < 0 || swapWith >= ids.length) {
            return;
        }

        [ids[position], ids[swapWith]] = [ids[swapWith], ids[position]];

        router.post(
            AiProviderCredentialModelsController.reorder.url(),
            { purpose, ordered_ids: ids },
            { preserveScroll: true },
        );
    }

    return (
        <div className="space-y-3 rounded-lg border p-3">
            <p className="text-sm text-muted-foreground">{description}</p>

            <div className="flex gap-2">
                <AddProviderDialog triggerLabel="Add Providers" />
                <AddModelDialog purpose={purpose} credentials={credentials} />
            </div>

            {entries.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No models added yet.
                </p>
            )}

            <div className="space-y-2">
                {entries.map((entry, position) => (
                    <div
                        key={entry.id}
                        className="flex items-center justify-between gap-2 rounded-md border p-2"
                    >
                        <div className="flex items-center gap-3">
                            <div className="flex flex-col">
                                <button
                                    type="button"
                                    onClick={() => move(entry.id, 'up')}
                                    disabled={position === 0}
                                    className="text-muted-foreground hover:text-foreground disabled:opacity-30"
                                    aria-label="Move up"
                                >
                                    ▲
                                </button>
                                <button
                                    type="button"
                                    onClick={() => move(entry.id, 'down')}
                                    disabled={position === entries.length - 1}
                                    className="text-muted-foreground hover:text-foreground disabled:opacity-30"
                                    aria-label="Move down"
                                >
                                    ▼
                                </button>
                            </div>

                            <div>
                                <p className="flex items-center gap-2 font-medium">
                                    {entry.model}
                                    {position === 0 && (
                                        <Badge variant="default">Default</Badge>
                                    )}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {entry.credential.label}
                                    {' · '}
                                    {providerLabel(entry.credential.provider)}
                                </p>
                            </div>
                        </div>

                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                router.delete(
                                    AiProviderCredentialModelsController.destroy.url(
                                        entry.id,
                                    ),
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Remove
                        </Button>
                    </div>
                ))}
            </div>
        </div>
    );
}

function AddModelDialog({
    purpose,
    credentials,
}: {
    purpose: 'default' | 'vision';
    credentials: Credential[];
}) {
    const [open, setOpen] = useState(false);
    const eligible = credentials.filter(
        (credential) => (credential.discovered_models?.length ?? 0) > 0,
    );
    const [credentialId, setCredentialId] = useState<number | null>(
        eligible[0]?.id ?? null,
    );

    const selected =
        eligible.find((credential) => credential.id === credentialId) ??
        eligible[0];

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" size="sm" variant="outline">
                    Add Model
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add a model</DialogTitle>
                </DialogHeader>

                {eligible.length === 0 || selected === undefined ? (
                    <p className="text-sm text-muted-foreground">
                        No discovered models yet. Add a provider (or reload an
                        existing one) first.
                    </p>
                ) : (
                    <Form
                        key={selected.id}
                        {...AiProviderCredentialModelsController.store.form(
                            selected.id,
                        )}
                        resetOnSuccess
                        onSuccess={() => setOpen(false)}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="purpose"
                                    value={purpose}
                                />

                                <div className="grid gap-2">
                                    <Label>Provider</Label>
                                    <select
                                        value={selected.id}
                                        onChange={(event) =>
                                            setCredentialId(
                                                Number(event.target.value),
                                            )
                                        }
                                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                                    >
                                        {eligible.map((credential) => (
                                            <option
                                                key={credential.id}
                                                value={credential.id}
                                            >
                                                {credential.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div className="grid gap-2">
                                    <Label>Models</Label>
                                    <div className="grid max-h-40 gap-1.5 overflow-y-auto">
                                        {(selected.discovered_models ?? []).map(
                                            (model) => (
                                                <label
                                                    key={model.id}
                                                    className="flex items-center gap-2 text-sm"
                                                >
                                                    <Checkbox
                                                        name="models[]"
                                                        value={model.id}
                                                    />
                                                    {model.label}
                                                </label>
                                            ),
                                        )}
                                    </div>
                                    <InputError message={errors.models} />
                                </div>

                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing}
                                >
                                    Add selected
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}

function AddProviderDialog({
    triggerLabel = 'Add Provider',
    triggerClassName,
}: {
    triggerLabel?: string;
    triggerClassName?: string;
}) {
    const [open, setOpen] = useState(false);
    const [provider, setProvider] = useState('anthropic');

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" size="sm" className={triggerClassName}>
                    {triggerLabel}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add an AI provider</DialogTitle>
                </DialogHeader>
                <Form
                    {...AiProviderCredentialsController.store.form()}
                    resetOnSuccess
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="label">Label</Label>
                                <Input
                                    id="label"
                                    name="label"
                                    required
                                    placeholder="e.g. Anthropic primary"
                                />
                                <InputError message={errors.label} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="provider">
                                    Provider format
                                </Label>
                                <select
                                    id="provider"
                                    name="provider"
                                    value={provider}
                                    onChange={(event) =>
                                        setProvider(event.target.value)
                                    }
                                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                                >
                                    <option value="anthropic">
                                        Anthropic-style
                                    </option>
                                    <option value="openai">OpenAI-style</option>
                                </select>
                                <InputError message={errors.provider} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="base_url">
                                    Base URL (optional)
                                </Label>
                                <Input
                                    id="base_url"
                                    name="base_url"
                                    placeholder={
                                        PROVIDER_DEFAULT_BASE_URL[provider]
                                    }
                                />
                                <InputError message={errors.base_url} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="api_key">API key</Label>
                                <Input
                                    id="api_key"
                                    type="password"
                                    name="api_key"
                                    required
                                    autoComplete="off"
                                />
                                <InputError message={errors.api_key} />
                            </div>

                            <Button disabled={processing}>Add provider</Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function ProviderModelsToggle({ models }: { models: DiscoveredModel[] }) {
    const [show, setShow] = useState(false);

    if (models.length === 0) {
        return null;
    }

    return (
        <div className="space-y-2">
            <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() => setShow((value) => !value)}
            >
                {show ? 'Hide Models' : 'Show Models'}
            </Button>

            {show && (
                <div className="flex flex-wrap gap-1.5">
                    {models.map((model) => (
                        <Badge key={model.id} variant="outline">
                            {model.label}
                        </Badge>
                    ))}
                </div>
            )}
        </div>
    );
}

export default function AiProvidersIndex({ credentials, models }: PageProps) {
    const [editingId, setEditingId] = useState<number | null>(null);

    function move(id: number, direction: 'up' | 'down') {
        const ids = credentials.map((credential) => credential.id);
        const position = ids.indexOf(id);
        const swapWith = direction === 'up' ? position - 1 : position + 1;

        if (swapWith < 0 || swapWith >= ids.length) {
            return;
        }

        [ids[position], ids[swapWith]] = [ids[swapWith], ids[position]];

        router.post(
            reorder().url,
            { ordered_ids: ids },
            { preserveScroll: true },
        );
    }

    return (
        <>
            <Head title="AI Models" />

            <div className="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
                <Heading
                    title="AI Models"
                    description="Add API keys under Providers, then pick which of their models actually get tried, and in what order, under Models. A default task tries default models first, then vision models as a further fallback; a vision task only ever uses vision models."
                />

                <Tabs defaultValue="models">
                    <div className="flex justify-center">
                        <TabsList>
                            <TabsTrigger value="models">Models</TabsTrigger>
                            <TabsTrigger value="providers">
                                Providers
                            </TabsTrigger>
                        </TabsList>
                    </div>

                    <TabsContent value="models">
                        <Tabs defaultValue="default">
                            <TabsList className="h-auto w-fit justify-start rounded-none border-b bg-transparent p-0">
                                <TabsTrigger
                                    value="default"
                                    className="rounded-none border-b-2 border-transparent px-3 pb-2 shadow-none data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:shadow-none"
                                >
                                    Default
                                </TabsTrigger>
                                <TabsTrigger
                                    value="vision"
                                    className="rounded-none border-b-2 border-transparent px-3 pb-2 shadow-none data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:shadow-none"
                                >
                                    Vision
                                </TabsTrigger>
                            </TabsList>

                            <TabsContent value="default">
                                <ModelChainSection
                                    description="Tried in order for any plain text/chat task. The top entry is the default, everything below it is a backup tried if it fails."
                                    purpose="default"
                                    entries={models.default}
                                    credentials={credentials}
                                />
                            </TabsContent>
                            <TabsContent value="vision">
                                <ModelChainSection
                                    description="Tried in order for any task that reads an image, and as a further fallback after default models run out. The top entry is the default, everything below it is a backup."
                                    purpose="vision"
                                    entries={models.vision}
                                    credentials={credentials}
                                />
                            </TabsContent>
                        </Tabs>
                    </TabsContent>

                    <TabsContent value="providers" className="space-y-3">
                        <AddProviderDialog />

                        {credentials.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No AI providers configured yet.
                            </p>
                        )}

                        {credentials.map((credential, position) => (
                            <div
                                key={credential.id}
                                className="space-y-3 rounded-lg border p-3"
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <div>
                                        <p className="flex items-center gap-2 font-medium">
                                            {credential.label}
                                            {position === 0 &&
                                                credential.enabled && (
                                                    <Badge variant="default">
                                                        Default
                                                    </Badge>
                                                )}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {providerLabel(credential.provider)}
                                            {credential.base_url &&
                                                ` · ${credential.base_url}`}
                                        </p>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        <Badge
                                            variant={
                                                credential.enabled
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                        >
                                            {credential.enabled
                                                ? 'Enabled'
                                                : 'Disabled'}
                                        </Badge>
                                        <Badge
                                            variant={
                                                credential.verified_at
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {credential.verified_at
                                                ? 'Verified'
                                                : 'Unverified'}
                                        </Badge>

                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8"
                                                    aria-label="More actions"
                                                >
                                                    <MoreVertical className="size-4" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem
                                                    disabled={position === 0}
                                                    onSelect={() =>
                                                        move(
                                                            credential.id,
                                                            'up',
                                                        )
                                                    }
                                                >
                                                    Move up
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    disabled={
                                                        position ===
                                                        credentials.length - 1
                                                    }
                                                    onSelect={() =>
                                                        move(
                                                            credential.id,
                                                            'down',
                                                        )
                                                    }
                                                >
                                                    Move down
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        router.post(
                                                            AiProviderCredentialsController.toggle.url(
                                                                credential.id,
                                                            ),
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    {credential.enabled
                                                        ? 'Disable'
                                                        : 'Enable'}
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        setEditingId(
                                                            editingId ===
                                                                credential.id
                                                                ? null
                                                                : credential.id,
                                                        )
                                                    }
                                                >
                                                    {editingId === credential.id
                                                        ? 'Cancel edit'
                                                        : 'Edit'}
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    onSelect={() =>
                                                        router.delete(
                                                            AiProviderCredentialsController.destroy.url(
                                                                credential.id,
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Delete
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                </div>

                                <Form
                                    {...AiProviderCredentialsController.verify.form(
                                        credential.id,
                                    )}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            <RefreshCw className="size-3.5" />
                                            Reload models
                                        </Button>
                                    )}
                                </Form>

                                <ProviderModelsToggle
                                    models={credential.discovered_models ?? []}
                                />

                                {editingId === credential.id && (
                                    <Form
                                        {...AiProviderCredentialsController.update.form(
                                            credential.id,
                                        )}
                                        onSuccess={() => setEditingId(null)}
                                        className="space-y-4 border-t pt-4"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <div className="grid gap-2">
                                                    <Label
                                                        htmlFor={`label-${credential.id}`}
                                                    >
                                                        Label
                                                    </Label>
                                                    <Input
                                                        id={`label-${credential.id}`}
                                                        name="label"
                                                        defaultValue={
                                                            credential.label
                                                        }
                                                        required
                                                    />
                                                    <InputError
                                                        message={errors.label}
                                                    />
                                                </div>

                                                <div className="grid gap-2">
                                                    <Label
                                                        htmlFor={`base_url-${credential.id}`}
                                                    >
                                                        Base URL (optional)
                                                    </Label>
                                                    <Input
                                                        id={`base_url-${credential.id}`}
                                                        name="base_url"
                                                        defaultValue={
                                                            credential.base_url ??
                                                            ''
                                                        }
                                                        placeholder={
                                                            PROVIDER_DEFAULT_BASE_URL[
                                                                credential
                                                                    .provider
                                                            ]
                                                        }
                                                    />
                                                    <InputError
                                                        message={
                                                            errors.base_url
                                                        }
                                                    />
                                                </div>

                                                <div className="grid gap-2">
                                                    <Label
                                                        htmlFor={`api_key-${credential.id}`}
                                                    >
                                                        New API key (leave blank
                                                        to keep the current one)
                                                    </Label>
                                                    <Input
                                                        id={`api_key-${credential.id}`}
                                                        type="password"
                                                        name="api_key"
                                                        autoComplete="off"
                                                    />
                                                    <InputError
                                                        message={errors.api_key}
                                                    />
                                                </div>

                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    Save
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                )}
                            </div>
                        ))}
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}

AiProvidersIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'AI Models', href: index() },
    ],
};
