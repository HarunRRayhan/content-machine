import { RefreshCw } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type PlatformEntry = {
    account_id: string | number | null;
    handle: string;
};

export type LanguageConfig = {
    workspace_id: string | null;
    platforms: Record<string, PlatformEntry>;
};

export type AvailableWorkspace = {
    id: string;
    name: string;
};

export function workspaceOptionLabel(workspace: AvailableWorkspace): string {
    if (workspace.name !== '' && workspace.name !== workspace.id) {
        return `${workspace.name} (${workspace.id})`;
    }

    return workspace.id;
}

function platformLabel(platform: string): string {
    return platform.charAt(0).toUpperCase() + platform.slice(1);
}

export function languageLabel(language: string): string {
    return language === 'bangla' ? 'Bangla' : 'English';
}

export function PostsyncerLanguageSection({
    language,
    config,
    platforms,
    availableWorkspaces,
    onRefresh,
    refreshing,
}: {
    language: string;
    config: LanguageConfig;
    platforms: string[];
    availableWorkspaces: AvailableWorkspace[];
    onRefresh: (language: string) => void;
    refreshing: boolean;
}) {
    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between gap-4">
                <Heading
                    variant="small"
                    title={`${languageLabel(language)} workspace`}
                    description="Pick the PostSyncer workspace, then refresh accounts and check the handles."
                />
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={refreshing}
                    onClick={() => onRefresh(language)}
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
                    defaultValue={config.workspace_id ?? ''}
                    className="h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm"
                >
                    <option value="">Select a workspace</option>
                    {availableWorkspaces.map((workspace) => (
                        <option key={workspace.id} value={workspace.id}>
                            {workspaceOptionLabel(workspace)}
                        </option>
                    ))}
                    {config.workspace_id &&
                        !availableWorkspaces.some(
                            (workspace) => workspace.id === config.workspace_id,
                        ) && (
                            <option value={config.workspace_id}>
                                {config.workspace_id} (saved)
                            </option>
                        )}
                </select>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b text-left">
                            <th className="py-2 pr-4 font-medium">Platform</th>
                            <th className="py-2 pr-4 font-medium">
                                Account id
                            </th>
                            <th className="py-2 font-medium">Handle</th>
                        </tr>
                    </thead>
                    <tbody>
                        {platforms.map((platform) => (
                            <tr
                                key={platform}
                                className="border-b last:border-0"
                            >
                                <td className="py-2 pr-4">
                                    {platformLabel(platform)}
                                </td>
                                <td className="py-2 pr-4">
                                    <Input
                                        name={`languages[${language}][platforms][${platform}][account_id]`}
                                        defaultValue={
                                            config.platforms[platform]
                                                ?.account_id ?? ''
                                        }
                                        placeholder="Account id"
                                    />
                                </td>
                                <td className="py-2">
                                    <Input
                                        name={`languages[${language}][platforms][${platform}][handle]`}
                                        defaultValue={
                                            config.platforms[platform]
                                                ?.handle ?? ''
                                        }
                                        placeholder="@handle"
                                    />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
