export interface PollOptionResultDto {
    id: number;
    label: string;
    count: number;
    percent: number;
}

export interface PollResultsDto {
    pollId: number;
    question: string;
    totalVotes: number;
    options: PollOptionResultDto[];
    isOpen: boolean;
}

export interface PollSummaryDto {
    id: number;
    question: string;
    status: 'draft' | 'open' | 'closed';
    statusLabel: string;
    totalVotes: number;
}
