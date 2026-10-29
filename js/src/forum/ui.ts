/*
 * The pages, in one chunk: they share the feed, the card and the thread, and
 * Flarum loads one chunk per lazy import, so separate page chunks would each
 * carry their own copy.
 */
export { default as MomentsPage } from './pages/MomentsPage';
export { default as MomentPage } from './pages/MomentPage';
export { default as UserMomentsPage } from './pages/UserMomentsPage';
