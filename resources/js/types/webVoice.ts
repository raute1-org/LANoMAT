export type WebVoiceChannelDto = {
    id: number;
    name: string;
    description: string | null;
    kind: 'general' | 'match' | 'team';
};

export type WebVoiceAudioConfig = {
    bitrate: number;
    dtx: boolean;
    red: boolean;
    echo_cancellation: boolean;
    noise_suppression: boolean;
    auto_gain_control: boolean;
    /** Receiver jitter buffer target in ms; `null` = browser default (adaptive). */
    jitter_buffer_target_ms: number | null;
};

export type WebVoiceTokenResponse = {
    url: string;
    token: string;
    identity: string;
    muted: boolean;
    audio: WebVoiceAudioConfig;
};

export type WebVoiceLatencyTokenResponse = {
    url: string;
    room: string;
    sender: { token: string; identity: string };
    receiver: { token: string; identity: string };
    audio: WebVoiceAudioConfig;
};

export type WebVoiceMessageDto = {
    id: number;
    channel_id: number;
    body: string;
    created_at: string;
    author: { id: number; name: string; avatar_url: string | null };
};

export type WebVoiceParticipantView = {
    identity: string;
    userId: number | null;
    name: string;
    speaking: boolean;
    micOn: boolean;
    canSpeak: boolean;
    isLocal: boolean;
};

export type WebVoiceRoomStatus =
    'idle' | 'connecting' | 'connected' | 'reconnecting' | 'error';
