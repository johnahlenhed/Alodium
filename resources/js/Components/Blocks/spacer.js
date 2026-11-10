// The spacer block's height bounds, in px: App\Blocks\Types\SpacerBlock
// (HEIGHT_MIN, HEIGHT_MAX, HEIGHT_DEFAULT). No imports, so the Laravel tests
// can load it with Node and compare (SiteThemeSyncTest).
//
// step: the slider's step. 2 rather than 8: from a 2 px floor, steps of 8 give
// 2, 10, 18, 26, 34, ... and miss the default (32) and every round number; steps
// of 2 reach every even height (2, 4, ... 32 ... 240) and are fine enough for
// the thin end, where a pixel or two shows.

export const SPACER_HEIGHT = Object.freeze({ min: 2, max: 240, default: 32, step: 2 });
