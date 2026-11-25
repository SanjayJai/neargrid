import type { PublicBusinessProfileDto } from '@/shared/api/directory-types';

export interface SavedBusinessIdsPayload {
  saved_ids: string[];
}

export interface SaveBusinessPayload {
  saved: boolean;
  public_id: string;
}

export interface SavedBusinessesPaginationMeta {
  type: 'offset';
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
  next_cursor: string | null;
}

export interface SavedBusinessesListPayload {
  data: PublicBusinessProfileDto[];
  meta: {
    pagination: SavedBusinessesPaginationMeta;
  };
}
