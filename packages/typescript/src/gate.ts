export type Timing = "before_order" | "before_payment" | "after_payment" | "without_payment";
export type Stage = "order" | "payment" | "fulfillment";

export interface Requirement {
  timing: Timing;
  completed: boolean;
}

const blockingStages: Record<Stage, readonly Timing[]> = {
  order: ["before_order"],
  payment: ["before_order", "before_payment"],
  fulfillment: ["before_order", "before_payment", "after_payment", "without_payment"],
};

export function allows(stage: Stage, requirements: readonly Requirement[]): boolean {
  return requirements.every(
    (requirement) => !blockingStages[stage].includes(requirement.timing) || requirement.completed === true,
  );
}
