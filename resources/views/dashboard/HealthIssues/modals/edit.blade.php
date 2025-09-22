<div class="modal fade" id="editHealthIssueModal{{ $healthIssue->id }}" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog">
        <form method="POST" action="{{ route('dashboard.health_issues.update', $healthIssue->id) }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit HealthIssue</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="إغلاق">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>اسم المشكلة الصحية (عربي)</label>
                        <input type="text" name="name_ar" value="{{ old('name_ar', $healthIssue->name_ar) }}"
                            class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>اسم المشكلة الصحية (إنجليزي)</label>
                        <input type="text" name="name_en" value="{{ old('name_en', $healthIssue->name_en) }}"
                            class="form-control" required>
                    </div>
 <div class="form-group">
            <label for="createBannerImage">Image</label>
            <input type="file" name="image" id="createhealthIssueImage" class="form-control">
          </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">تحديث</button>
                </div>
            </div>
        </form>
    </div>
</div>