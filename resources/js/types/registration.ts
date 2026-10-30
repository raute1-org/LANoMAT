export interface ArrivalSummary {
    arrivalDay: string | null;
    arrivalWindow: string | null;
    departureDay: string | null;
    departureWindow: string | null;
    arrivalLabel: string | null;
    departureLabel: string | null;
    editable: boolean;
}

export interface ArrivalOptions {
    days: { value: string; label: string }[];
    windows: { value: string; label: string }[];
}

export interface RegistrationDetails {
    ticketType: string;
    ticketLabel: string;
    status: 'pending' | 'confirmed' | 'cancelled';
    paid: boolean;
    checkedIn: boolean;
    /** Ruling C: false once checked in or the event is over. */
    canCancel: boolean;
    cancelBlockedReason: 'checked_in' | 'event_over' | null;
    qrSvg: string;
    arrival: ArrivalSummary;
}
