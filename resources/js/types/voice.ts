/**
 * A downloadable client installer for one platform, current for its
 * (provider, platform) pair — see VoiceSetupController::currentInstallers().
 */
export interface VoiceInstallerDto {
    id: number;
    platform: 'windows' | 'macos' | 'linux';
    platformLabel: string;
    version: string;
    originalName: string;
}

/**
 * Connect data + current installers for one active voice provider — see
 * VoiceSetupController::providerDto().
 */
export interface VoiceProviderSetupDto {
    provider: 'mumble' | 'teamspeak';
    label: string;
    host: string;
    port: string;
    joinLink: string;
    installers: VoiceInstallerDto[];
}
