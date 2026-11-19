/** Money in the operator report is exact: a decimal string with nine decimals, no rounding. */
export type ReportFigures = {
    generations: number;
    userCharge: string;
    providerCost: string;
    profit: string;
};

export type ReportUser = {
    name: string;
    email: string;
};

export type ReportRow = ReportFigures & {
    user: ReportUser;
};

export type ReportDay = {
    date: string;
    rows: ReportRow[];
    totals: ReportFigures;
};

export type OperatorReport = {
    days: ReportDay[];
    totals: ReportFigures;
};

export type ReportPeriod = {
    start: string;
    end: string;
};

/** Our provider cost for some days next to what the provider reports for them. */
export type CostComparison = {
    ours: string;
    provider: string;
    generations: {
        ours: number;
        provider: number;
    };
    matches: boolean;
    difference: string;
    providerIsHigher: boolean;
};

export type Reconciliation = {
    days: Record<string, CostComparison>;
    total: CostComparison;
};

export type ChargeAboveEstimate = {
    id: string;
    user: ReportUser;
    completedAt: string;
    estimateMax: string;
    userCharge: string;
    excess: string;
};
