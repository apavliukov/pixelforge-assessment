/** An amount as the backend prepared it: rounded for display, exact for a tooltip. */
export type Amount = {
    formatted: string;
    exact: string;
};

export type Estimate = {
    min: Amount;
    max: Amount;
};

export type ImageSize = '1024x1024' | '1536x1024' | '1024x1536';

export type ImageQuality = 'low' | 'medium' | 'high';

export type GenerationStatus =
    | 'waiting'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type Generation = {
    id: string;
    prompt: string;
    size: ImageSize;
    quality: ImageQuality;
    reference_image_count: number;
    status: GenerationStatus;
    status_label: string;
    is_final: boolean;
    failure_reason: string | null;
    estimate: Estimate;
    charge: Amount | null;
    image_url: string | null;
    image_expired: boolean;
    submitted_at: string;
};

export type Paginated<T> = {
    data: T[];
    links: {
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
    };
};

export type GenerationLimits = {
    promptLength: number;
    referenceImages: number;
};

export type GenerationDefaults = {
    size: ImageSize;
    quality: ImageQuality;
};
