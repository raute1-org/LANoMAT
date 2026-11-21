import type { ChoiceGroupDto } from '@/lib/menuChoices';

export interface MenuOptionDto {
    key: string;
    name: string;
    priceCents: number;
    description?: string | null;
    imageUrl?: string | null;
    /** Selection groups of a set menu (empty for an ordinary dish). */
    choices?: ChoiceGroupDto[];
}

export interface RestaurantDto {
    name: string;
    description: string | null;
    logoUrl: string | null;
}

export interface ItemPickDto {
    group: string;
    optionKey: string;
    /** Name snapshot, with its category prefix. */
    name: string;
}

export interface FoodOrderItemDto {
    id: number;
    optionKey: string | null;
    name?: string | null;
    note: string | null;
    /** The picks of a set menu; empty for an ordinary dish. */
    choices?: ItemPickDto[];
    priceCents: number;
    paid: boolean;
    included: boolean;
}

export interface MyFoodPayment {
    method: 'cash' | 'paypal' | 'included';
    dueCents: number;
    url: string | null;
    reference: string | null;
}

export interface FoodOrderDto {
    id: number;
    title: string;
    status: 'draft' | 'open' | 'closed';
    statusLabel: string;
    isOpen: boolean;
    opensAt: string | null;
    closesAt: string | null;
    restaurant: RestaurantDto | null;
    menu: MenuOptionDto[];
    myItems: FoodOrderItemDto[];
    myTotalCents: number;
    myPayment: MyFoodPayment | null;
}
