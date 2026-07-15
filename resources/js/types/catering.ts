export interface MenuOptionDto {
    key: string;
    name: string;
    priceCents: number;
}

export interface FoodOrderItemDto {
    id: number;
    optionKey: string | null;
    note: string | null;
    priceCents: number;
    paid: boolean;
}

export interface FoodOrderDto {
    id: number;
    title: string;
    status: 'draft' | 'open' | 'closed';
    statusLabel: string;
    isOpen: boolean;
    opensAt: string | null;
    closesAt: string | null;
    menu: MenuOptionDto[];
    myItems: FoodOrderItemDto[];
    myTotalCents: number;
}
