/*
 * Admin entry point.
 *
 * Deliberately shares no module with the public bundle: a module imported by
 * both would be hoisted into a chunk both entries load, which is exactly what
 * the split is meant to prevent. Admin gets its own copy of anything it needs.
 */

export {};
