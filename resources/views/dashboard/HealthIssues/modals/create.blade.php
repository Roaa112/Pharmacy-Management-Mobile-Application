<div class="modal fade" id="createHealthIssueModal" tabindex="-1" aria-labelledby="createHealthIssueModalLabel"
    aria-hidden="true">
    <div class="modal-dialog">
        <form action="{{ route('dashboard.health_issues.store') }}" method="POST"enctype="multipart/form-data">
            @csrf

            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"> HealthIssue Name</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>اسم المشكلة الصحية (عربي)</label>
                        <input type="text" name="name_ar" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>اسم المشكلة الصحية (إنجليزي)</label>
                        <input type="text" name="name_en" class="form-control" required>
                    </div>
  <div class="form-group">
            <label for="createBannerImage">Image</label>
            <input type="file" name="image" id="createhealthIssueImage" class="form-control">
          </div>
                </div>
               
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Add</button>
                </div>
            </div>
        </form>
    </div>
</div>