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
}: {
    description: string;
    purpose: 'default' | 'vision';
    entries: ModelEntry[];
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

function AddModelsForm({ credential }: { credential: Credential }) {
    const discovered = credential.discovered_models ?? [];

    if (discovered.length === 0) {
        return null;
    }

    return (
        <Form
            {...AiProviderCredentialModelsController.store.form(credential.id)}
            resetOnSuccess
            className="space-y-2 rounded-md border border-dashed p-3"
        >
            {({ processing, errors }) => (
                <>
                    <Label>Add models to the fallback chain</Label>
                    <div className="grid max-h-40 gap-1.5 overflow-y-auto">
                        {discovered.map((model) => (
                            <label
                                key={model.id}
                                className="flex items-center gap-2 text-sm"
                            >
                                <Checkbox name="models[]" value={model.id} />
                                {model.label}
                            </label>
                        ))}
                    </div>
                    <InputError message={errors.models} />

                    <div className="flex items-center gap-2">
                        <select
                            name="purpose"
                            defaultValue="default"
                            className="flex h-9 rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                        >
                            <option value="default">As default</option>
                            <option value="vision">As vision</option>
                        </select>
                        <Button type="submit" size="sm" disabled={processing}>
                            Add selected
                        </Button>
                    </div>
                    <InputError message={errors.purpose} />
                </>
            )}
        </Form>
    );
}

export default function AiProvidersIndex({ credentials, models }: PageProps) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const [newProvider, setNewProvider] = useState('anthropic');
    const [addOpen, setAddOpen] = useState(false);

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
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title="AI Models"
                        description="Add API keys as providers on the right, then pick which of their models actually get tried, and in what order, on the left. A default task tries default models first, then vision models as a further fallback; a vision task only ever uses vision models."
                    />

                    <Dialog open={addOpen} onOpenChange={setAddOpen}>
                        <DialogTrigger asChild>
                            <Button className="shrink-0">Add provider</Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Add an AI provider</DialogTitle>
                            </DialogHeader>
                            <Form
                                {...AiProviderCredentialsController.store.form()}
                                resetOnSuccess
                                onSuccess={() => setAddOpen(false)}
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
                                            <InputError
                                                message={errors.label}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="provider">
                                                Provider format
                                            </Label>
                                            <select
                                                id="provider"
                                                name="provider"
                                                value={newProvider}
                                                onChange={(event) =>
                                                    setNewProvider(
                                                        event.target.value,
                                                    )
                                                }
                                                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                                            >
                                                <option value="anthropic">
                                                    Anthropic-style
                                                </option>
                                                <option value="openai">
                                                    OpenAI-style
                                                </option>
                                            </select>
                                            <InputError
                                                message={errors.provider}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="base_url">
                                                Base URL (optional)
                                            </Label>
                                            <Input
                                                id="base_url"
                                                name="base_url"
                                                placeholder={
                                                    PROVIDER_DEFAULT_BASE_URL[
                                                        newProvider
                                                    ]
                                                }
                                            />
                                            <InputError
                                                message={errors.base_url}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="api_key">
                                                API key
                                            </Label>
                                            <Input
                                                id="api_key"
                                                type="password"
                                                name="api_key"
                                                required
                                                autoComplete="off"
                                            />
                                            <InputError
                                                message={errors.api_key}
                                            />
                                        </div>

                                        <Button disabled={processing}>
                                            Add provider
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </DialogContent>
                    </Dialog>
                </div>

                <Tabs defaultValue="models">
                    <TabsList>
                        <TabsTrigger value="models">Models</TabsTrigger>
                        <TabsTrigger value="providers">Providers</TabsTrigger>
                    </TabsList>

                    <TabsContent value="models">
                        <Tabs defaultValue="default">
                            <TabsList>
                                <TabsTrigger value="default">
                                    Default
                                </TabsTrigger>
                                <TabsTrigger value="vision">Vision</TabsTrigger>
                            </TabsList>

                            <TabsContent value="default">
                                <ModelChainSection
                                    description="Tried in order for any plain text/chat task. The top entry is the default, everything below it is a backup tried if it fails."
                                    purpose="default"
                                    entries={models.default}
                                />
                            </TabsContent>
                            <TabsContent value="vision">
                                <ModelChainSection
                                    description="Tried in order for any task that reads an image, and as a further fallback after default models run out. The top entry is the default, everything below it is a backup."
                                    purpose="vision"
                                    entries={models.vision}
                                />
                            </TabsContent>
                        </Tabs>
                    </TabsContent>

                    <TabsContent value="providers" className="space-y-3">
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

                                <AddModelsForm credential={credential} />

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
