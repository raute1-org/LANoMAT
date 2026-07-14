export interface RegistrationDetails {
    ticketType: string;
    status: 'pending' | 'confirmed' | 'cancelled';
    paid: boolean;
    checkedIn: boolean;
    qrSvg: string;
}
