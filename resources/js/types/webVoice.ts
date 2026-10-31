export type WebVoiceChannelDto = {
    id: number;
    name: string;
    description: string | null;
    kind: 'general' | 'match';
};

export type WebVoiceAudioConfig = {
    bitrate: number;
    dtx: boolean;
    red: boolean;
    echo_cancellation: boolean;
    noise_suppression: boolean;
    auto_gain_control: boolean;
};

export type WebVoiceTokenResponse = {
    url: string;
    token: string;
    identity: string;
    muted: boolean;
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
