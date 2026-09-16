/**
 * Validated 8-hue categorical palette (dataviz skill, references/palette.md).
 * Order is the CVD-safety mechanism, not cosmetic — never reorder ad hoc, and
 * never generate a 9th hue; fold extra series into "Other" instead.
 *
 * Use this for genuinely categorical series with no inherent status meaning
 * (contract type mix, department mix, counterparty mix). A series that
 * already carries status meaning (contract status, risk severity) uses the
 * semantic maps in this file instead — contractStatusColors, riskLevelColors,
 * workflowStatusColors — so it stays visually consistent with the status
 * badges used for the same values everywhere else in the app.
 */
export const CATEGORICAL_PALETTE = [
  '#2a78d6', // blue
  '#eb6834', // orange
  '#1baf7a', // aqua
  '#eda100', // yellow
  '#e87ba4', // magenta
  '#008300', // green
  '#4a3aa7', // violet
  '#e34948', // red
] as const;

export function categoricalColor(index: number): string {
  return CATEGORICAL_PALETTE[index % CATEGORICAL_PALETTE.length];
}
