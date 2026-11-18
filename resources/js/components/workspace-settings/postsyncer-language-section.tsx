import { RefreshCw } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

export type PlatformEntry = {
    account_id: string | number | null;
    handle: string;
    enabled: boolean;
};

export type LanguageConfig = {
    workspace_id: string | null;
    platforms: Record<string, PlatformEntry>;
};

export type WorkspaceAccount = {
    id: string;
    platform: string;
    handle: string;
};

export type AvailableWorkspace = {
    id: string;
    name: string;
    accounts: WorkspaceAccount[];
};

export type PostTypesConfig = {
    platforms?: Record<string, Record<string, string | null>>;
    overrides?: Record<string, Record<string, Record<string, string | null>>>;
};

export function workspaceOptionLabel(workspace: AvailableWorkspace): string {
    if (workspace.name !== '' && workspace.name !== workspace.id) {
        return `${workspace.name} (${workspace.id})`;
    }

    return workspace.id;
}

export function platformsFromAccounts(
    platformNames: string[],
    accounts: WorkspaceAccount[],
    existing: Record<string, PlatformEntry> = {},
): Record<string, PlatformEntry> {
    const byPlatform = new Map(
        accounts.map((account) => [account.platform, account]),
    );
    const next: Record<string, PlatformEntry> = {};

    for (const platform of platformNames) {
        const account = byPlatform.get(platform);
        const current = existing[platform];

        next[platform] = {
            account_id: account?.id ?? '',
            handle: account?.handle ?? '',
            enabled:
                current?.enabled !== undefined
                    ? current.enabled
                    : Boolean(account),
        };
    }

    return next;
}

export function connectedPlatforms(
    platformsByName: Record<string, PlatformEntry>,
    platformNames: string[],
): string[] {
    return platformNames.filter((platform) =>
        Boolean(platformsByName[platform]?.account_id),
    );
}

export function platformLabel(platform: string): string {
    return platform.charAt(0).toUpperCase() + platform.slice(1);
}

export function languageLabel(language: string): string {
    return language === 'bangla' ? 'Bangla' : 'English';
}

export function activeTypeLabels(
    language: string,
    platform: string,
    postTypes: PostTypesConfig,
    postTypeNames: string[],
): string[] {
    const base = postTypes.platforms?.[platform] ?? {};
    const override = postTypes.overrides?.[language]?.[platform] ?? {};

    return postTypeNames.filter((type) => {
        const state = override[type] ?? base[type] ?? '';

        return state === 'on' || state === 'ask';
    });
}

export function PostsyncerLanguageSection({
    language,
    workspaceId,
    platformsByName,
    platforms,
    availableWorkspaces,
    postTypes,
    postTypeNames,
    onWorkspaceChange,
    onPlatformChange,
    onRefresh,
    refreshing,
}: {
    language: string;
    workspaceId: string;
    platformsByName: Record<string, PlatformEntry>;
    platforms: string[];
    availableWorkspaces: AvailableWorkspace[];
    postTypes: PostTypesConfig;
    postTypeNames: string[];
    onWorkspaceChange: (language: string, workspaceId: string) => void;
    onPlatformChange: (
        language: string,
        platform: string,
        patch: Partial<PlatformEntry>,
    ) => void;
    onRefresh: (language: string, workspaceId: string) => void;
    refreshing: boolean;
}) {
    const pulled = connectedPlatforms(platformsByName, platforms);
    const workspacePicked = workspaceId !== '';

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between gap-4">
                <Heading
                    variant="small"
                    title={`${languageLabel(language)} workspace`}
                    description={
                        workspacePicked
                            ? 'These handles are in that workspace. Turn on the ones you want to use.'
                            : 'Pick a workspace. We will pull its accounts next.'
                    }
                />
                {workspacePicked && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={refreshing}
                        onClick={() => onRefresh(language, workspaceId)}
                    >
                        <RefreshCw
                            className={`mr-2 size-4 ${refreshing ? 'animate-spin' : ''}`}
                        />
                        Refresh
                    </Button>
                )}
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`${language}-workspace-id`}>Workspace</Label>
                <select
                    id={`${language}-workspace-id`}
                    name={`languages[${language}][workspace_id]`}
                    value={workspaceId}
                    onChange={(event) =>
                        onWorkspaceChange(language, event.target.value)
                    }
                    className="h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm"
                >
                    <option value="">Select a workspace</option>
                    {availableWorkspaces.map((workspace) => (
                        <option key={workspace.id} value={workspace.id}>
                            {workspaceOptionLabel(workspace)}
                        </option>
                    ))}
                    {workspacePicked &&
                        !availableWorkspaces.some(
                            (workspace) => workspace.id === workspaceId,
                        ) && (
                            <option value={workspaceId}>
                                {workspaceId} (saved)
                            </option>
                        )}
                </select>
            </div>

            <div className="hidden">
                {platforms.map((platform) => {
                    const entry = platformsByName[platform] ?? {
                        account_id: '',
                        handle: '',
                        enabled: false,
                    };

                    return (
                        <div key={`fields-${platform}`}>
                            <input
                                type="hidden"
                                name={`languages[${language}][platforms][${platform}][enabled]`}
                                value={entry.enabled ? '1' : '0'}
                            />
                            <input
                                type="hidden"
                                name={`languages[${language}][platforms][${platform}][account_id]`}
                                value={entry.account_id ?? ''}
                            />
                            <input
                                type="hidden"
                                name={`languages[${language}][platforms][${platform}][handle]`}
                                value={entry.handle}
                            />
                        </div>
                    );
                })}
            </div>

            {workspacePicked && refreshing && pulled.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Pulling accounts…
                </p>
            )}

            {workspacePicked && !refreshing && pulled.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No social accounts came back for this workspace.
                </p>
            )}

            {workspacePicked && pulled.length > 0 && (
                <ul className="divide-y rounded-lg border">
                    {pulled.map((platform) => {
                        const entry = platformsByName[platform];
                        const handle =
                            entry.handle !== ''
                                ? entry.handle
                                : platformLabel(platform);
                        const types = activeTypeLabels(
                            language,
                            platform,
                            postTypes,
                            postTypeNames,
                        );

                        return (
                            <li
                                key={platform}
                                className="flex items-start justify-between gap-4 px-4 py-3"
                            >
                                <label className="flex min-w-0 flex-1 cursor-pointer items-start gap-3">
                                    <input
                                        type="checkbox"
                                        checked={entry.enabled}
                                        aria-label={`Use ${handle} on ${platformLabel(platform)}`}
                                        onChange={(event) =>
                                            onPlatformChange(
                                                language,
                                                platform,
                                                {
                                                    enabled:
                                                        event.target.checked,
                                                },
                                            )
                                        }
                                        className="mt-1 size-4 rounded border-input"
                                    />
                                    <span className="min-w-0">
                                        <span className="block font-medium">
                                            {handle}
                                        </span>
                                        <span className="block text-sm text-muted-foreground">
                                            {platformLabel(platform)}
                                            {types.length > 0
                                                ? ` · ${types.join(', ')}`
                                                : ''}
                                        </span>
                                    </span>
                                </label>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
