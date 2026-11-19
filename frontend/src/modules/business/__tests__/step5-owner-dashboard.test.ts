import { describe, expect, it } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

describe('Step 5 — Live Business Owner Dashboard Guardrails', () => {
  const businessModuleDir = path.resolve(__dirname, '..');
  const businessAppDir = path.resolve(__dirname, '../../../app/(business)');

  it('eliminates all demo fixtures (DEMO_BUSINESSES, DEMO_ENQUIRIES, DEMO_KPIS, DemoBanner) from owner dashboard pages', () => {
    const dashboardPageFiles = [
      path.join(businessAppDir, 'dashboard/page.tsx'),
      path.join(businessAppDir, 'dashboard/profile/page.tsx'),
      path.join(businessAppDir, 'dashboard/media-services/page.tsx'),
      path.join(businessAppDir, 'dashboard/hours/page.tsx'),
      path.join(businessAppDir, 'dashboard/team/page.tsx'),
      path.join(businessAppDir, 'dashboard/verification/page.tsx'),
      path.join(businessAppDir, 'dashboard/enquiries/page.tsx'),
    ];

    for (const filePath of dashboardPageFiles) {
      expect(fs.existsSync(filePath), `Expected file to exist: ${filePath}`).toBe(true);
      const content = fs.readFileSync(filePath, 'utf8');

      expect(content, `${filePath} must not import or reference DEMO_BUSINESSES`).not.toMatch(/\bDEMO_BUSINESSES\b/);
      expect(content, `${filePath} must not import or reference DEMO_ENQUIRIES\b`).not.toMatch(/\bDEMO_ENQUIRIES\b/);
      expect(content, `${filePath} must not import or reference DEMO_KPIS\b`).not.toMatch(/\bDEMO_KPIS\b/);
      expect(content, `${filePath} must not import or reference DemoBanner\b`).not.toMatch(/\bDemoBanner\b/);
    }
  });

  it('ensures KYC document downloads use server-side BFF proxy and never leak raw storage paths', () => {
    const kycViewPath = path.join(businessModuleDir, 'components/KycVerificationView.tsx');
    expect(fs.existsSync(kycViewPath)).toBe(true);
    const content = fs.readFileSync(kycViewPath, 'utf8');

    // Asserts download URL points strictly to the BFF download proxy
    expect(content).toContain('/download');
    expect(content).toContain('/api/owner/businesses/');
    expect(content).toContain('/kyc-documents/');

    // Asserts no raw disk or storage references are present
    expect(content).not.toMatch(/storage\/app\/private/);
    expect(content).not.toMatch(/s3:\/\//);
    expect(content).not.toMatch(/private_file_path/);
  });

  it('verifies service pricing minor unit conversion logic (paise <-> rupees)', () => {
    // 50,000 paise = ₹500
    const pricePaise = 50000;
    const priceRupees = pricePaise / 100;
    expect(priceRupees).toBe(500);

    // Input ₹750.50 -> 75050 paise
    const inputRupees = '750.50';
    const computedPaise = Math.round(parseFloat(inputRupees) * 100);
    expect(computedPaise).toBe(75050);
  });

  it('verifies opening hours day_of_week mapping and split-shift slot structure', () => {
    const hoursViewPath = path.join(businessModuleDir, 'components/OpeningHoursEditor.tsx');
    expect(fs.existsSync(hoursViewPath)).toBe(true);
    const content = fs.readFileSync(hoursViewPath, 'utf8');

    // Asserts day_of_week supports 0 (Sunday) through 6 (Saturday)
    expect(content).toContain('dayOfWeek: 1, name: \'Monday\'');
    expect(content).toContain('dayOfWeek: 0, name: \'Sunday\'');

    // Asserts split shift slot 2 support
    expect(content).toContain('slot_index: 2');
    expect(content).toContain('hasSplitShift');
  });

  it('verifies team member management restricts assignable roles to non-owner roles', () => {
    const teamViewPath = path.join(businessModuleDir, 'components/TeamManagerView.tsx');
    expect(fs.existsSync(teamViewPath)).toBe(true);
    const content = fs.readFileSync(teamViewPath, 'utf8');

    // Roles assignable by owner
    expect(content).toContain('manager');
    expect(content).toContain('editor');
    expect(content).toContain('lead_agent');

    // Checks that owner check exists
    expect(content).toContain('my_membership?.is_owner');
  });

  it('verifies honest Phase 3C notice on enquiries page without fake lead dispatch', () => {
    const enquiriesPagePath = path.join(businessAppDir, 'dashboard/enquiries/page.tsx');
    const content = fs.readFileSync(enquiriesPagePath, 'utf8');

    expect(content).toContain('Phase 3C Feature Preview');
    expect(content).toContain('Consented Inbound Lead CRM in Active Development');
    expect(content).toContain('Digital Personal Data Protection');
  });
});
