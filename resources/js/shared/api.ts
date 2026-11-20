function csrfToken(): string {
    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
    return meta?.content ?? '';
}

export class ApiError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        public readonly payload: Record<string, unknown> = {},
    ) {
        super(message);
    }
}

async function parseResponse<T>(res: Response): Promise<T> {
    const text = await res.text();
    let data: Record<string, unknown> = {};
    if (text !== '') {
        try {
            data = JSON.parse(text) as Record<string, unknown>;
        } catch {
            data = { message: text };
        }
    }
    if (!res.ok) {
        const msg = typeof data.message === 'string' ? data.message : `Request failed (${res.status})`;
        throw new ApiError(res.status, msg, data);
    }
    return data as T;
}

export async function apiGet<T>(url: string): Promise<T> {
    const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    return parseResponse<T>(res);
}

export async function apiJson<T>(method: 'POST' | 'PUT' | 'DELETE', url: string, body?: unknown): Promise<T> {
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });
    return parseResponse<T>(res);
}

export async function apiUpload<T>(url: string, formData: FormData): Promise<T> {
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: formData,
    });
    return parseResponse<T>(res);
}
