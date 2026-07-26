export type AlertType = 'threshold' | 'missing_logs';
export type AlertState = 'ok' | 'firing';
export type ChannelType = 'email' | 'webhook' | 'telegram';

export type AlertEventItem = {
    state: AlertState;
    measuredValue: number;
    createdAt: string;
};

export type AlertItem = {
    id: number;
    name: string;
    type: AlertType;
    state: AlertState;
    enabled: boolean;
    config: {
        servers: number[];
        sources: string[];
        search: string | null;
        threshold: number | null;
        window_minutes: number;
    };
    channelIds: number[];
    lastStateChangeAt: string | null;
    events: AlertEventItem[];
};

export type ChannelItem = {
    id: number;
    type: ChannelType;
    name: string;
    enabled: boolean;
    failureCount: number;
    config: { address?: string; url?: string; chat_id?: string };
};
