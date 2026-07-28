export type ServerStatus = 'pending' | 'online' | 'offline';

export type ServerSummary = {
    id: number;
    name: string;
    hostname: string | null;
    status: ServerStatus;
    lastSeenAt: string | null;
    bytesToday: number;
};

export type ServerDetail = ServerSummary & {
    agentVersion: string | null;
    retentionDays: number | null;
};

export type LogSourceType = 'systemd' | 'docker' | 'nginx' | 'file';

export type LogSourceItem = {
    id: number;
    type: LogSourceType;
    name: string;
    config: {
        units?: string[];
        containers?: string[];
        access_log?: string;
        error_log?: string;
        paths?: string[];
    };
    enabled: boolean;
};

export type LogEntry = {
    ts: string;
    time: string;
    line: string;
    labels: Record<string, string>;
};

export type ServerHealth = {
    lastPollAt: string | null;
    lastPushAt: string | null;
    configStatus: 'synced' | 'syncing' | 'unknown';
    configSyncedAt: string | null;
    usage: { date: string; bytes: number }[];
};
