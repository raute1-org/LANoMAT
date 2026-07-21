/**
 * The wire shape produced by `EventPageController::renderShow()` for the
 * `news` prop — published orga news posts (see `NewsQuery::published()`),
 * newest first, already filtered to published/non-future posts server-side.
 */
export interface NewsPostDto {
    id: number;
    title: string;
    body: string;
    publishedAt: string | null;
}
