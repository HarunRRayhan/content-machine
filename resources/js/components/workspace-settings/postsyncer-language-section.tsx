import { RefreshCw } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
            account_id: account?.id ?? current?.account_id ?? '',
            handle: account?.handle ?? current?.handle ?? '',
            enabled:
                current?.enabled !== undefined
                    ? current.enabled
                    : Boolean(account),
        };
    }

    return next;
}

function platformLabel(platform: string): string {
    return platform.charAt(0).toUpperCase() + platform.slice(1);
}

export function languageLabel(language: string): string {
    return language === 'bangla' ? 'Bangla' : 'English';
}

export function PostsyncerLanguageSection({
    language,
    workspaceId,
    platformsByName,
    platforms,
    availableWorkspaces,
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
    onWorkspaceChange: (language: string, workspaceId: string) => void;
    onPlatformChange: (
        language: string,
        platform: string,
        patch: Partial<PlatformEntry>,
    ) => void;
    onRefresh: (language: string, workspaceId: string) => void;
    refreshing: boolean;
}) {
    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between gap-4">
                <Heading
                    variant="small"
                    title={`${languageLabel(language)} workspace`}
                    description="Pick a workspace. Accounts fill in; then enable or disable each platform."
                />
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={refreshing || workspaceId === ''}
                    onClick={() => onRefresh(language, workspaceId)}
                >
                    <RefreshCw
                        className={`mr-2 size-4 ${refreshing ? 'animate-spin' : ''}`}
                    />
                    Refresh accounts
                </Button>
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
                    {workspaceId !== '' &&
                        !availableWorkspaces.some(
                            (workspace) => workspace.id === workspaceId,
                        ) && (
                            <option value={workspaceId}>
                                {workspaceId} (saved)
                            </option>
                        )}
                </select>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b text-left">
                            <th className="py-2 pr-4 font-medium">Use</th>
                            <th className="py-2 pr-4 font-medium">Platform</th>
                            <th className="py-2 pr-4 font-medium">
                                Account id
                            </th>
                            <th className="py-2 font-medium">Handle</th>
                        </tr>
                    </thead>
                    <tbody>
                        {platforms.map((platform) => {
                            const entry = platformsByName[platform] ?? {
                                account_id: '',
                                handle: '',
                                enabled: false,
                            };

                            return (
                                <tr
                                    key={platform}
                                    className="border-b last:border-0"
                                >
                                    <td className="py-2 pr-4">
                                        <input
                                            type="hidden"
                                            name={`languages[${language}][platforms][${platform}][enabled]`}
                                            value={entry.enabled ? '1' : '0'}
                                        />
                                        <input
                                            type="checkbox"
                                            checked={entry.enabled}
                                            aria-label={`Enable ${platformLabel(platform)}`}
                                            onChange={(event) =>
                                                onPlatformChange(
                                                    language,
                                                    platform,
                                                    {
                                                        enabled:
                                                            event.target
                                                                .checked,
                                                    },
                                                )
                                            }
                                            className="size-4 rounded border-input"
                                        />
                                    </td>
                                    <td className="py-2 pr-4">
                                        {platformLabel(platform)}
                                    </td>
                                    <td className="py-2 pr-4">
                                        <Input
                                            name={`languages[${language}][platforms][${platform}][account_id]`}
                                            value={entry.account_id ?? ''}
                                            onChange={(event) =>
                                                onPlatformChange(
                                                    language,
                                                    platform,
                                                    {
                                                        account_id:
                                                            event.target.value,
                                                    },
                                                )
                                            }
                                            placeholder="Account id"
                                        />
                                    </td>
                                    <td className="py-2">
                                        <Input
                                            name={`languages[${language}][platforms][${platform}][handle]`}
                                            value={entry.handle}
                                            onChange={(event) =>
                                                onPlatformChange(
                                                    language,
                                                    platform,
                                                    {
                                                        handle: event.target
                                                            .value,
                                                    },
                                                )
                                            }
                                            placeholder="@handle"
                                        />
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
