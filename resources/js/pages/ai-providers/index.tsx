import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AiProviderCredentialsController from '@/actions/App/Http/Controllers/AiProviders/AiProviderCredentialsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { home } from '@/routes/dashboard';
import { index, reorder } from '@/routes/dashboard/ai-providers';

type Credential = {
    id: number;
    label: string;
    provider: string;
    base_url: string | null;
    model: string;
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

export default function AiProvidersIndex({ credentials }: PageProps) {
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
            <Head title="AI Providers" />

            <div className="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
                <Heading
                    title="AI Providers"
                    description="API keys for AI features. Tried top to bottom; if one fails, the next is used."
                />

                <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                    <Form
                        {...AiProviderCredentialsController.store.form()}
                        resetOnSuccess
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

                                <div className="grid grid-cols-2 gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="provider">
                                            Provider format
                                        </Label>
                                        <select
                                            id="provider"
                                            name="provider"
                                            defaultValue="anthropic"
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
                                        <Label htmlFor="model">Model</Label>
                                        <Input
                                            id="model"
                                            name="model"
                                            required
                                            placeholder="e.g. claude-sonnet-4-5"
                                        />
                                        <InputError message={errors.model} />
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="base_url">
                                        Base URL (optional)
                                    </Label>
                                    <Input
                                        id="base_url"
                                        name="base_url"
                                        placeholder="Leave blank for the provider's default"
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
                </div>

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
                                            {providerLabel(credential.provider)}{' '}
                                            &middot; {credential.model}
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
                                                        credential.model
                                                    }
                                                    required
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
