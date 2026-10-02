/**
 * Thin JSON client for the AtlasScope API.
 */
export function createApi(root) {
    const url = (name) => root.dataset[name];

    const get = async (endpoint, params = {}) => {
        const target = new URL(endpoint, window.location.origin);

        Object.entries(params).forEach(([key, value]) => {
            if (value !== null && value !== undefined && value !== '') {
                target.searchParams.set(key, value);
            }
        });

        const response = await fetch(target, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (response.status === 404 || response.status === 202) {
            return response.json().catch(() => ({ ready: false }));
        }

        if (!response.ok) {
            throw new Error(`Request failed (${response.status}) for ${target.pathname}`);
        }

        return response.json();
    };

    return {
        graph: () => get(url('graphUrl')),
        status: () => get(url('statusUrl')),
        events: (after = 0) => get(url('eventsUrl'), { after }),
        node: (key) => get(url('nodeUrl').replace('__KEY__', encodeURIComponent(key).replace(/%2F/gi, '/'))),
        file: (path, from = 0, to = 0) => get(url('fileUrl'), { path, from, to }),
        exportUrl: () => url('exportUrl'),
        projectUrl: () => url('projectUrl'),
    };
}
