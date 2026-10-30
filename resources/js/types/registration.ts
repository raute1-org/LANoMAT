export interface RegistrationDetails {
    ticketType: string;
    ticketLabel: string;
    status: 'pending' | 'confirmed' | 'cancelled';
    paid: boolean;
    checkedIn: boolean;
    qrSvg: string;
}
