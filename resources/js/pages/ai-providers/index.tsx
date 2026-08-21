import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AiProviderCredentialsController from '@/actions/App/Http/Controllers/AiProviders/AiProviderCredentialsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
    model: string | null;
    discovered_models: DiscoveredModel[] | null;
    priority: number;
    enabled: boolean;
    verified_at: string | null;
};

type PageProps = {
    credentials: Credential[];
};

function providerLabel(provider: string): string {
    return provider === 'anthropic' ? 'Anthropic-style' : 'OpenAI-style';
}

const PROVIDER_DEFAULT_BASE_URL: Record<string, string> = {
    anthropic: 'https://api.anthropic.com',
    openai: 'https://api.openai.com/v1',
};

function ModelPicker({ credential }: { credential: Credential }) {
    const discovered = credential.discovered_models ?? [];
    const hasDiscovered = discovered.length > 0;

    return (
        <Form
            {...AiProviderCredentialsController.setModel.form(credential.id)}
            className="flex flex-wrap items-end gap-2 rounded-md border border-dashed p-3"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor={`set-model-${credential.id}`}>
                            {hasDiscovered
                                ? 'Choose a model'
                                : "Couldn't detect models automatically. Enter one"}
                        </Label>
                        {hasDiscovered ? (
                            <select
                                id={`set-model-${credential.id}`}
                                name="model"
                                required
                                className="flex h-9 w-64 rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                            >
                                {discovered.map((model) => (
                                    <option key={model.id} value={model.id}>
                                        {model.label}
                                    </option>
                                ))}
                            </select>
                        ) : (
                            <Input
                                id={`set-model-${credential.id}`}
                                name="model"
                                required
                                placeholder="e.g. claude-sonnet-4-5"
                                className="w-64"
                            />
                        )}
                        <InputError message={errors.model} />
                    </div>
                    <Button type="submit" size="sm" disabled={processing}>
                        Set model
                    </Button>
                </>
            )}
        </Form>
    );
}

export default function AiProvidersIndex({ credentials }: PageProps) {
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
            <Head title="AI Providers" />

            <div className="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
                <Heading
                    title="AI Providers"
                    description="API keys for AI features. Tried top to bottom; if one fails, the next is used. No need to know the model name: add the key and its model is detected automatically."
                />

                <div className="space-y-3">
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
                                <div className="flex items-center gap-3">
                                    <div className="flex flex-col">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                move(credential.id, 'up')
                                            }
                                            disabled={position === 0}
                                            className="text-muted-foreground hover:text-foreground disabled:opacity-30"
                                            aria-label="Move up"
                                        >
                                            ▲
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                move(credential.id, 'down')
                                            }
                                            disabled={
                                                position ===
                                                credentials.length - 1
                                            }
                                            className="text-muted-foreground hover:text-foreground disabled:opacity-30"
                                            aria-label="Move down"
                                        >
                                            ▼
                                        </button>
                                    </div>

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
                                            {' · '}
                                            {credential.model ??
                                                'Model not set'}
                                            {credential.base_url &&
                                                ` · ${credential.base_url}`}
                                        </p>
                                    </div>
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
                                </div>
                            </div>

                            {credential.model === null && (
                                <ModelPicker credential={credential} />
                            )}

                            <div className="flex flex-wrap gap-2">
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
                                            Verify
                                        </Button>
                                    )}
                                </Form>

                                <Form
                                    {...AiProviderCredentialsController.toggle.form(
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
                                            {credential.enabled
                                                ? 'Disable'
                                                : 'Enable'}
                                        </Button>
                                    )}
                                </Form>

                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        setEditingId(
                                            editingId === credential.id
                                                ? null
                                                : credential.id,
                                        )
                                    }
                                >
                                    {editingId === credential.id
                                        ? 'Cancel'
                                        : 'Edit'}
                                </Button>

                                <Form
                                    {...AiProviderCredentialsController.destroy.form(
                                        credential.id,
                                    )}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="destructive"
                                            disabled={processing}
                                        >
                                            Delete
                                        </Button>
                                    )}
                                </Form>
                            </div>

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
                                                    htmlFor={`model-${credential.id}`}
                                                >
                                                    Model
                                                </Label>
                                                <Input
                                                    id={`model-${credential.id}`}
                                                    name="model"
                                                    defaultValue={
                                                        credential.model ?? ''
                                                    }
                                                />
                                                <InputError
                                                    message={errors.model}
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
                                                            credential.provider
                                                        ]
                                                    }
                                                />
                                                <InputError
                                                    message={errors.base_url}
                                                />
                                            </div>

                                            <div className="grid gap-2">
                                                <Label
                                                    htmlFor={`api_key-${credential.id}`}
                                                >
                                                    New API key (leave blank to
                                                    keep the current one)
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
                </div>

                <Dialog open={addOpen} onOpenChange={setAddOpen}>
                    <DialogTrigger asChild>
                        <Button className="self-start">Add credential</Button>
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
                                        <InputError message={errors.label} />
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
                                                PROVIDER_DEFAULT_BASE_URL[
                                                    newProvider
                                                ]
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

                                    <Button disabled={processing}>
                                        Add credential
                                    </Button>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </>
    );
}

AiProvidersIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'AI Providers', href: index() },
    ],
};
